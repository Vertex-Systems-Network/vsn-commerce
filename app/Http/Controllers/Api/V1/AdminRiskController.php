<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Risk\Services\RiskEvaluator;
use App\Domain\Risk\Services\RiskRecorder;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\RiskCase;
use App\Models\RiskEvent;
use App\Models\RiskHold;
use App\Models\RiskProfile;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Defines the AdminRiskController class and its project responsibilities. */
class AdminRiskController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        $this->viewer($request);

        RiskHold::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        return response()->json(['data' => [
            'summary' => [
                'openCases' => RiskCase::query()->whereIn('status', ['open', 'reviewing'])->count(),
                'activeHolds' => RiskHold::query()->where('status', 'active')->count(),
                'highRiskUsers' => RiskProfile::query()->whereNotNull('user_id')->whereIn('level', ['high', 'critical'])->count(),
                'highRiskVendors' => RiskProfile::query()->whereNotNull('vendor_id')->whereIn('level', ['high', 'critical'])->count(),
                'critical' => RiskProfile::query()->where('level', 'critical')->count(),
            ],
            'profiles' => RiskProfile::query()
                ->with(['user:id,name,email', 'vendor:id,name,slug'])
                ->orderByDesc('score')
                ->limit(100)
                ->get()
                ->map(fn (RiskProfile $profile): array => $this->profile($profile)),
            'cases' => RiskCase::query()
                ->with(['user:id,name,email', 'vendor:id,name,slug', 'assignee:id,name,email'])
                ->whereIn('status', ['open', 'reviewing'])
                ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
                ->latest('opened_at')
                ->limit(100)
                ->get()
                ->map(fn (RiskCase $case): array => $this->caseRow($case)),
            'recentEvents' => RiskEvent::query()
                ->with(['user:id,name,email', 'vendor:id,name,slug'])
                ->latest('occurred_at')
                ->limit(100)
                ->get()
                ->map(fn (RiskEvent $event): array => $this->event($event)),
            'holds' => RiskHold::query()
                ->with(['user:id,name,email', 'vendor:id,name,slug', 'creator:id,name,email'])
                ->where('status', 'active')
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (RiskHold $hold): array => $this->holdRow($hold)),
            'mode' => config('vsn.risk.mode', 'observe'),
            'autoHoldCritical' => (bool) config('vsn.risk.auto_hold_critical', false),
            'reviewScore' => (int) config('vsn.risk.review_score', 50),
            'criticalScore' => (int) config('vsn.risk.critical_score', 75),
        ]]);
    }

    /** Handles evaluate user for the admin risk controller workflow. */
    public function evaluateUser(Request $request, User $user, RiskEvaluator $risk): JsonResponse
    {
        $this->reviewer($request);

        return response()->json(['data' => $this->profile($risk->user($user, 'admin_manual'))]);
    }

    /** Handles evaluate vendor for the admin risk controller workflow. */
    public function evaluateVendor(Request $request, Vendor $vendor, RiskEvaluator $risk): JsonResponse
    {
        $this->reviewer($request);

        return response()->json(['data' => $this->profile($risk->vendor($vendor, 'admin_manual'))]);
    }

    /** Handles hold for the admin risk controller workflow. */
    public function hold(Request $request, RiskRecorder $events): JsonResponse
    {
        $this->reviewer($request);

        $data = $request->validate([
            'userId' => 'nullable|integer|exists:users,id',
            'vendorId' => 'nullable|integer|exists:vendors,id',
            'caseId' => 'nullable|string|exists:risk_cases,public_id',
            'scope' => ['required', Rule::in(['all', 'payments', 'wallet', 'games', 'returns', 'affiliate', 'payouts'])],
            'reason' => 'required|string|min:5|max:2000',
            'expiresAt' => 'nullable|date|after:now',
        ]);

        abort_unless((! empty($data['userId'])) xor (! empty($data['vendorId'])), 422, 'Choose exactly one user or vendor.');

        $case = ! empty($data['caseId'])
            ? RiskCase::query()->where('public_id', $data['caseId'])->first()
            : null;

        if ($case) {
            abort_if(! empty($data['userId']) && $case->user_id !== (int) $data['userId'], 422, 'Risk case belongs to a different user.');
            abort_if(! empty($data['vendorId']) && $case->vendor_id !== (int) $data['vendorId'], 422, 'Risk case belongs to a different vendor.');
        }

        $duplicate = RiskHold::query()
            ->where('scope', $data['scope'])
            ->where('status', 'active')
            ->when(! empty($data['userId']), fn ($query) => $query->where('user_id', (int) $data['userId']))
            ->when(! empty($data['vendorId']), fn ($query) => $query->where('vendor_id', (int) $data['vendorId']))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        abort_if($duplicate, 422, 'An active hold already exists for this scope.');

        $hold = RiskHold::create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $data['userId'] ?? null,
            'vendor_id' => $data['vendorId'] ?? null,
            'risk_case_id' => $case?->id,
            'created_by_user_id' => $request->user()->id,
            'scope' => $data['scope'],
            'status' => 'active',
            'reason' => $data['reason'],
            'starts_at' => now(),
            'expires_at' => $data['expiresAt'] ?? null,
        ]);

        $events->record(
            $hold->user,
            $hold->vendor,
            'manual_risk_hold_created',
            'high',
            0,
            $hold->scope,
            'risk_hold',
            $hold->public_id,
            "risk-hold-created:{$hold->public_id}",
            ['actorUserId' => $request->user()->id, 'reason' => $hold->reason],
        );

        return response()->json([
            'data' => $this->holdRow($hold->load(['user:id,name,email', 'vendor:id,name,slug', 'creator:id,name,email'])),
        ], 201);
    }

    /** Handles release for the admin risk controller workflow. */
    public function release(Request $request, RiskHold $hold, RiskRecorder $events): JsonResponse
    {
        $this->reviewer($request);
        $data = $request->validate(['note' => 'required|string|min:3|max:2000']);

        if ($hold->status !== 'active') {
            return response()->json([
                'data' => $this->holdRow($hold->load(['user:id,name,email', 'vendor:id,name,slug', 'creator:id,name,email'])),
            ]);
        }

        $hold->update([
            'status' => 'released',
            'released_by_user_id' => $request->user()->id,
            'released_at' => now(),
            'release_note' => $data['note'],
        ]);

        $events->record(
            $hold->user,
            $hold->vendor,
            'manual_risk_hold_released',
            'medium',
            0,
            $hold->scope,
            'risk_hold',
            $hold->public_id,
            "risk-hold-released:{$hold->public_id}",
            ['actorUserId' => $request->user()->id, 'note' => $data['note']],
        );

        return response()->json([
            'data' => $this->holdRow($hold->fresh()->load(['user:id,name,email', 'vendor:id,name,slug', 'creator:id,name,email'])),
        ]);
    }

    /** Handles case update for the admin risk controller workflow. */
    public function caseUpdate(Request $request, RiskCase $case): JsonResponse
    {
        $this->reviewer($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'reviewing', 'resolved', 'dismissed'])],
            'resolution' => 'nullable|string|max:3000',
            'assignedToUserId' => 'nullable|integer|exists:users,id',
        ]);

        $case->update([
            'status' => $data['status'],
            'resolution' => $data['resolution'] ?? $case->resolution,
            'assigned_to_user_id' => $data['assignedToUserId'] ?? $case->assigned_to_user_id,
            'closed_at' => in_array($data['status'], ['resolved', 'dismissed'], true) ? now() : null,
        ]);

        return response()->json([
            'data' => $this->caseRow($case->fresh()->load(['user:id,name,email', 'vendor:id,name,slug', 'assignee:id,name,email'])),
        ]);
    }

    /** Handles viewer for the admin risk controller workflow. */
    private function viewer(Request $request): void
    {
        $role = $request->user()?->role instanceof UserRole
            ? $request->user()->role->value
            : (string) $request->user()?->role;

        abort_unless(in_array($role, [
            UserRole::Support->value,
            UserRole::Finance->value,
            UserRole::Moderator->value,
            UserRole::Admin->value,
            UserRole::SuperAdmin->value,
        ], true), 403);
    }

    /** Handles reviewer for the admin risk controller workflow. */
    private function reviewer(Request $request): void
    {
        $role = $request->user()?->role instanceof UserRole
            ? $request->user()->role->value
            : (string) $request->user()?->role;

        abort_unless(in_array($role, [
            UserRole::Moderator->value,
            UserRole::Admin->value,
            UserRole::SuperAdmin->value,
        ], true), 403);
    }

    /** Handles profile for the admin risk controller workflow. */
    private function profile(RiskProfile $profile): array
    {
        return [
            'id' => $profile->public_id,
            'user' => $profile->user ? [
                'id' => $profile->user->id,
                'name' => $profile->user->name,
                'email' => $profile->user->email,
            ] : null,
            'vendor' => $profile->vendor ? [
                'id' => $profile->vendor->id,
                'name' => $profile->vendor->name,
                'slug' => $profile->vendor->slug,
            ] : null,
            'score' => $profile->score,
            'level' => $profile->level,
            'status' => $profile->status,
            'signals' => $profile->signal_summary ?: [],
            'lastEvaluatedAt' => $profile->last_evaluated_at?->toIso8601String(),
        ];
    }

    /** Handles case row for the admin risk controller workflow. */
    private function caseRow(RiskCase $case): array
    {
        return [
            'id' => $case->public_id,
            'status' => $case->status,
            'priority' => $case->priority,
            'title' => $case->title,
            'summary' => $case->summary,
            'scoreAtOpen' => $case->score_at_open,
            'resolution' => $case->resolution,
            'user' => $case->user ? [
                'id' => $case->user->id,
                'name' => $case->user->name,
                'email' => $case->user->email,
            ] : null,
            'vendor' => $case->vendor ? [
                'id' => $case->vendor->id,
                'name' => $case->vendor->name,
            ] : null,
            'assignee' => $case->assignee?->name,
            'openedAt' => $case->opened_at?->toIso8601String(),
            'closedAt' => $case->closed_at?->toIso8601String(),
        ];
    }

    /** Handles event for the admin risk controller workflow. */
    private function event(RiskEvent $event): array
    {
        return [
            'id' => $event->public_id,
            'type' => $event->event_type,
            'scope' => $event->scope,
            'severity' => $event->severity,
            'scoreDelta' => $event->score_delta,
            'user' => $event->user?->email,
            'vendor' => $event->vendor?->name,
            'sourceType' => $event->source_type,
            'sourceId' => $event->source_id,
            'metadata' => $event->metadata ?: [],
            'occurredAt' => $event->occurred_at?->toIso8601String(),
        ];
    }

    /** Handles hold row for the admin risk controller workflow. */
    private function holdRow(RiskHold $hold): array
    {
        return [
            'id' => $hold->public_id,
            'scope' => $hold->scope,
            'status' => $hold->status,
            'reason' => $hold->reason,
            'user' => $hold->user ? [
                'id' => $hold->user->id,
                'name' => $hold->user->name,
                'email' => $hold->user->email,
            ] : null,
            'vendor' => $hold->vendor ? [
                'id' => $hold->vendor->id,
                'name' => $hold->vendor->name,
            ] : null,
            'createdBy' => $hold->creator?->name,
            'startsAt' => $hold->starts_at?->toIso8601String(),
            'expiresAt' => $hold->expires_at?->toIso8601String(),
            'releasedAt' => $hold->released_at?->toIso8601String(),
        ];
    }
}
