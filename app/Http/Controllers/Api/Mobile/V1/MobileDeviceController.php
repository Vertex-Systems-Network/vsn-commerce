<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Domain\Mobile\Services\MobileTokenService;
use App\Domain\Security\Services\SecurityRecorder;
use App\Enums\SecuritySeverity;
use App\Http\Controllers\Controller;
use App\Models\MobileApiSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/** Defines the MobileDeviceController class and its project responsibilities. */
class MobileDeviceController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        $current = $request->attributes->get('mobile_session');
        $rows = MobileApiSession::query()->where('user_id', $request->user()->id)->latest('last_seen_at')->get();

        return response()->json(['data' => $rows->map(/** Inline callback for this operation. */ fn (MobileApiSession $row) => [
            'id' => $row->public_id,
            'deviceName' => $row->device_name,
            'platform' => $row->platform,
            'appVersion' => $row->app_version,
            'osVersion' => $row->os_version,
            'pushEnabled' => filled($row->push_token_hash),
            'pushTokenUpdatedAt' => $row->push_token_updated_at?->toIso8601String(),
            'refreshGeneration' => (int) $row->refresh_generation,
            'lastSeenAt' => $row->last_seen_at?->toIso8601String(),
            'refreshExpiresAt' => $row->refresh_expires_at?->toIso8601String(),
            'compromisedAt' => $row->compromised_at?->toIso8601String(),
            'compromiseReason' => $row->compromise_reason,
            'revokedAt' => $row->revoked_at?->toIso8601String(),
            'current' => $current instanceof MobileApiSession && (int) $row->id === (int) $current->id,
        ])->all()]);
    }

    /** Handles revoke for the mobile device controller workflow. */
    public function revoke(Request $request, MobileApiSession $session, MobileTokenService $tokens, SecurityRecorder $events): JsonResponse
    {
        abort_unless((int) $session->user_id === (int) $request->user()->id, 404);
        $tokens->revoke($session);
        $events->record($request->user(), 'mobile_session_revoked', SecuritySeverity::Medium, $request, ['sessionId' => $session->public_id]);
        return response()->json(['data' => ['ok' => true]]);
    }

    /** Handles update push token for the mobile device controller workflow. */
    public function updatePushToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:fcm'],
            'token' => ['required', 'string', 'min:20', 'max:4096'],
        ]);
        $session = $this->currentSession($request);

        $session = DB::transaction(/** Inline callback for this operation. */ function () use ($session, $data): MobileApiSession {
            $session = MobileApiSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', (string) $data['token']);

            // One FCM registration token belongs to one active VSN installation at a time.
            MobileApiSession::query()->where('push_token_hash', $hash)->where('id', '!=', $session->id)
                ->update(['push_token' => null, 'push_token_hash' => null, 'push_provider' => null, 'push_token_updated_at' => null]);

            $session->forceFill([
                'push_provider' => $data['provider'],
                'push_token' => $data['token'],
                'push_token_hash' => $hash,
                'push_token_updated_at' => now(),
            ])->save();
            return $session;
        }, 3);

        return response()->json(['data' => [
            'registered' => true,
            'provider' => $session->push_provider,
            'updatedAt' => $session->push_token_updated_at?->toIso8601String(),
        ]]);
    }

    /** Handles remove push token for the mobile device controller workflow. */
    public function removePushToken(Request $request): JsonResponse
    {
        $session = $this->currentSession($request);
        $session->forceFill([
            'push_provider' => null,
            'push_token' => null,
            'push_token_hash' => null,
            'push_token_updated_at' => null,
        ])->save();

        return response()->json(['data' => ['registered' => false, 'provider' => null]]);
    }

    /** Handles current session for the mobile device controller workflow. */
    private function currentSession(Request $request): MobileApiSession
    {
        $session = $request->attributes->get('mobile_session');
        if ($session instanceof MobileApiSession) return $session;

        $current = $request->user()->currentAccessToken();
        abort_unless($current instanceof PersonalAccessToken, 401);
        return MobileApiSession::query()->where('user_id', $request->user()->id)
            ->where('access_token_id', $current->getKey())->firstOrFail();
    }
}
