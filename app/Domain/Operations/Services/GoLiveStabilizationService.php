<?php

namespace App\Domain\Operations\Services;

use App\Enums\PaymentIntentStatus;
use App\Enums\UserRole;
use App\Models\FinanceReconciliationRun;
use App\Models\GoLiveObservation;
use App\Models\GoLiveStabilizationSignoff;
use App\Models\GoLiveWindow;
use App\Models\IncidentRecord;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\ProviderReconciliationRun;
use App\Models\ProviderRuntimeStatus;
use App\Models\ReleaseCandidateManifest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Defines the GoLiveStabilizationService class and its project responsibilities. */
class GoLiveStabilizationService
{
    /** Initializes the GoLiveStabilizationService instance and its dependencies. */
    public function __construct(
        private readonly ProductionAcceptanceService $acceptance,
        private readonly OperationalHealthService $health,
        private readonly LaunchGateService $launchGate,
        private readonly IncidentManagementService $incidents,
    ) {}

    /** Handles open for the go live stabilization service workflow. */
    public function open(?User $actor = null): GoLiveWindow
    {
        $gate = $this->acceptance->goLiveStatus();
        abort_unless(($gate['ready'] ?? false) === true, 409, 'Final go-live gate is not READY for this exact release candidate.');
        abort_unless(Schema::hasTable('go_live_windows'), 503, 'Go-live stabilization schema is not available.');

        $manifestId = data_get($gate, 'releaseCandidate.id');
        $manifest = $manifestId
            ? ReleaseCandidateManifest::query()->where('public_id', $manifestId)->firstOrFail()
            : ReleaseCandidateManifest::query()->where('release', (string) config('vsn.operations.release'))->latest('sealed_at')->firstOrFail();
        $manifest->loadMissing(['acceptanceRun','deploymentRun']);
        abort_unless($manifest->acceptanceRun && $manifest->deploymentRun, 409, 'Release-candidate seal is missing acceptance or deployment evidence.');

        $environment = app()->environment();
        $thresholds = $this->thresholds();
        $window = DB::transaction(/** Inline callback for this operation. */ function () use ($actor, $manifest, $environment, $thresholds): GoLiveWindow {
            $existing = GoLiveWindow::query()->where('active_environment', $environment)->lockForUpdate()->first();
            abort_if($existing, 409, "An active go-live stabilization window already exists for {$environment}.");

            return GoLiveWindow::query()->create([
                'public_id'=>(string) Str::ulid(),
                'release_candidate_manifest_id'=>$manifest->id,
                'production_acceptance_run_id'=>$manifest->acceptance_run_id,
                'deployment_run_id'=>$manifest->deployment_run_id,
                'opened_by_user_id'=>$actor?->id,
                'release'=>$manifest->release,
                'environment'=>$environment,
                'active_environment'=>$environment,
                'status'=>'monitoring',
                'artifact_sha256'=>$manifest->artifact_sha256,
                'composer_lock_sha256'=>$manifest->composer_lock_sha256,
                'npm_lock_sha256'=>$manifest->npm_lock_sha256,
                'verification_sha256'=>$manifest->verification_sha256,
                'release_manifest_sha256'=>$manifest->manifest_sha256,
                'observation_interval_minutes'=>$thresholds['observationIntervalMinutes'],
                'required_healthy_observations'=>$thresholds['requiredHealthyObservations'],
                'thresholds'=>$thresholds,
                'baseline'=>$this->activitySnapshot(now()),
                'opened_at'=>now(),
                'rollback_expires_at'=>now()->addMinutes($thresholds['rollbackWindowMinutes']),
                'stabilization_due_at'=>now()->addMinutes($thresholds['stabilizationMinutes']),
            ]);
        }, 3);

        $this->observe($window, false);
        return $window->fresh(['observations','signoffs','releaseCandidateManifest']);
    }

    /** Handles observe for the go live stabilization service workflow. */
    public function observe(GoLiveWindow $window, bool $autoIncident = true): GoLiveObservation
    {
        abort_unless($window->active_environment !== null && $window->status === 'monitoring', 409, 'Only an active monitoring window can record observations.');
        $snapshot = $this->snapshot($window);
        $blockers = $this->blockers($snapshot, $window);
        $warnings = $this->warnings($snapshot, $window);
        $status = $blockers === [] ? 'healthy' : 'blocked';

        $observation = DB::transaction(/** Inline callback for this operation. */ function () use ($window, $snapshot, $blockers, $warnings, $status): GoLiveObservation {
            $locked = GoLiveWindow::query()->lockForUpdate()->findOrFail($window->id);
            abort_unless($locked->status === 'monitoring' && $locked->active_environment !== null, 409, 'Go-live window is no longer active.');
            $sequence = ((int) GoLiveObservation::query()->where('go_live_window_id', $locked->id)->max('sequence')) + 1;
            return GoLiveObservation::query()->create([
                'public_id'=>(string) Str::ulid(), 'go_live_window_id'=>$locked->id, 'sequence'=>$sequence,
                'status'=>$status, 'blocker_count'=>count($blockers), 'warning_count'=>count($warnings),
                'snapshot'=>$snapshot, 'blockers'=>$blockers, 'warnings'=>$warnings, 'observed_at'=>now(),
            ]);
        }, 3);

        if ($autoIncident && $blockers !== [] && ! $window->fresh()->incident_record_id && (bool) config('vsn.go_live.auto_open_incident', true)) {
            $incident = $this->incidents->open(
                null,
                'sev2',
                'go_live_stabilization',
                'Go-live stabilization blocker — '.$window->release,
                'Automated stabilization observation detected blocking production conditions: '.implode(', ', array_column($blockers, 'code')).'.',
                ['goLiveWindowId'=>$window->public_id,'observationId'=>$observation->public_id,'blockers'=>$blockers]
            );
            GoLiveWindow::query()->whereKey($window->id)->whereNull('incident_record_id')->update(['incident_record_id'=>$incident->id]);
        }

        return $observation;
    }

    /** Handles status for the go live stabilization service workflow. */
    public function status(?GoLiveWindow $window = null): array
    {
        $window ??= GoLiveWindow::query()->with(['observations','signoffs','incident','releaseCandidateManifest'])->latest('id')->first();
        if (! $window) return ['exists'=>false,'readyForSignoff'=>false,'stable'=>false,'checkedAt'=>now()->toIso8601String()];
        $window->loadMissing(['observations','signoffs','incident','releaseCandidateManifest']);
        $ordered = $window->observations->sortByDesc('sequence')->values();
        $consecutive = 0;
        foreach ($ordered as $row) { if ($row->status !== 'healthy') break; $consecutive++; }
        $latest = $ordered->first();
        $maxAge = max(2, ((int) $window->observation_interval_minutes * 2) + 2);
        $latestFresh = $latest?->observed_at?->gte(now()->subMinutes($maxAge)) ?? false;
        $due = $window->stabilization_due_at?->lte(now()) ?? false;
        $critical = IncidentRecord::query()->whereIn('severity',['sev1','sev2'])->whereNotIn('status',['resolved','closed'])->count();
        $required = $this->requiredSignoffs();
        $allApproved = collect($required)->every(/** Inline callback for this operation. */ fn ($area) => $window->signoffs->contains(/** Inline callback for this operation. */ fn ($s) => $s->area === $area && $s->decision === 'approved'));
        $ready = $window->status === 'monitoring' && $due && $latestFresh && $latest?->status === 'healthy'
            && $consecutive >= (int) $window->required_healthy_observations && $critical === 0;
        return [
            'exists'=>true,'readyForSignoff'=>$ready,'stable'=>$window->status === 'stable','status'=>$window->status,
            'window'=>$this->windowRow($window),'latestObservation'=>$latest?$this->observationRow($latest):null,
            'consecutiveHealthy'=>$consecutive,'requiredHealthy'=>(int)$window->required_healthy_observations,
            'stabilizationDue'=>(bool)$due,'latestObservationFresh'=>(bool)$latestFresh,'criticalIncidents'=>$critical,
            'requiredSignoffs'=>$required,'allSignoffsApproved'=>$allApproved,
            'rollbackWindowOpen'=>$window->rollback_expires_at?->isFuture() ?? false,
            'checkedAt'=>now()->toIso8601String(),
        ];
    }

    /** Handles sign for the go live stabilization service workflow. */
    public function sign(GoLiveWindow $window, User $user, string $area, string $decision, ?string $comment = null): array
    {
        abort_unless(in_array($area, $this->requiredSignoffs(), true), 422, 'Unknown stabilization sign-off area.');
        abort_unless(in_array($decision, ['approved','rejected'], true), 422, 'Decision must be approved or rejected.');
        $this->authorizeArea($user, $area);
        abort_unless($window->status === 'monitoring', 409, 'Only an active monitoring window can be signed.');
        $state = $this->status($window);
        abort_unless($state['readyForSignoff'], 409, 'Stabilization evidence is not ready for sign-off.');
        if ((bool) config('vsn.go_live.require_distinct_signers', false) && $window->signoffs()->where('signed_by_user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['signoff'=>['Post-launch stabilization requires a different authorized signer for each area.']]);
        }
        $latest = $window->observations()->latest('sequence')->firstOrFail();
        GoLiveStabilizationSignoff::query()->create([
            'go_live_window_id'=>$window->id,'area'=>$area,'signed_by_user_id'=>$user->id,'decision'=>$decision,'comment'=>$comment,
            'evidence'=>['release'=>$window->release,'artifactSha256'=>$window->artifact_sha256,'releaseManifestSha256'=>$window->release_manifest_sha256,'latestObservationId'=>$latest->public_id,'latestObservationSequence'=>$latest->sequence,'latestObservationStatus'=>$latest->status,'consecutiveHealthy'=>$state['consecutiveHealthy']],
            'signed_at'=>now(),
        ]);
        $window->refresh()->load('signoffs');
        if ($window->signoffs->contains('decision','rejected')) {
            $window->update(['status'=>'failed','active_environment'=>null,'closed_at'=>now(),'closed_by_user_id'=>$user->id,'close_note'=>'Post-launch stabilization sign-off rejected.']);
        } elseif (collect($this->requiredSignoffs())->every(/** Inline callback for this operation. */ fn ($a) => $window->signoffs->contains(/** Inline callback for this operation. */ fn ($s) => $s->area === $a && $s->decision === 'approved'))) {
            $window->update(['status'=>'stable','active_environment'=>null,'stable_at'=>now(),'closed_at'=>now(),'closed_by_user_id'=>$user->id,'close_note'=>'Post-launch stabilization sign-offs completed.']);
        }
        return $this->status($window->fresh());
    }

    /** Handles rolled back for the go live stabilization service workflow. */
    public function rolledBack(GoLiveWindow $window, ?User $actor, string $targetRelease, string $note): array
    {
        abort_unless(in_array($window->status, ['monitoring','failed'], true), 409, 'This launch window cannot be marked rolled back.');
        abort_unless(trim($targetRelease) !== '', 422, 'Rollback target release is required.');
        $window->update([
            'status'=>'rolled_back','active_environment'=>null,'rolled_back_at'=>now(),'closed_at'=>now(),
            'closed_by_user_id'=>$actor?->id,'close_note'=>trim($note).' Target release: '.trim($targetRelease),
        ]);
        return $this->status($window->fresh());
    }

    /** Handles window row for the go live stabilization service workflow. */
    public function windowRow(GoLiveWindow $w): array
    {
        $w->loadMissing(['signoffs','incident','releaseCandidateManifest']);
        return [
            'id'=>$w->public_id,'release'=>$w->release,'environment'=>$w->environment,'status'=>$w->status,
            'artifactSha256'=>$w->artifact_sha256,'releaseManifestSha256'=>$w->release_manifest_sha256,
            'openedAt'=>$w->opened_at?->toIso8601String(),'rollbackExpiresAt'=>$w->rollback_expires_at?->toIso8601String(),
            'stabilizationDueAt'=>$w->stabilization_due_at?->toIso8601String(),'stableAt'=>$w->stable_at?->toIso8601String(),
            'rolledBackAt'=>$w->rolled_back_at?->toIso8601String(),'closedAt'=>$w->closed_at?->toIso8601String(),
            'incidentId'=>$w->incident?->public_id,'closeNote'=>$w->close_note,
            'observationIntervalMinutes'=>(int)$w->observation_interval_minutes,'requiredHealthyObservations'=>(int)$w->required_healthy_observations,
            'thresholds'=>$w->thresholds ?: [],
            'signoffs'=>$w->signoffs->map(/** Inline callback for this operation. */ fn($s)=>['area'=>$s->area,'decision'=>$s->decision,'signedBy'=>$s->signed_by_user_id,'comment'=>$s->comment,'signedAt'=>$s->signed_at?->toIso8601String()])->all(),
        ];
    }

    /** Handles observation row for the go live stabilization service workflow. */
    public function observationRow(GoLiveObservation $o): array
    {
        return ['id'=>$o->public_id,'sequence'=>(int)$o->sequence,'status'=>$o->status,'blockerCount'=>(int)$o->blocker_count,'warningCount'=>(int)$o->warning_count,'blockers'=>$o->blockers ?: [],'warnings'=>$o->warnings ?: [],'snapshot'=>$o->snapshot,'observedAt'=>$o->observed_at?->toIso8601String()];
    }

    /** Handles required signoffs for the go live stabilization service workflow. */
    public function requiredSignoffs(): array
    {
        $allowed=['operations','finance','business_owner'];
        $configured=array_values(array_unique(array_filter(array_map('trim',(array)config('vsn.go_live.required_signoffs',$allowed)),/** Inline callback for this operation. */ fn($x)=>in_array($x,$allowed,true))));
        return $configured ?: $allowed;
    }

    /** Handles snapshot for the go live stabilization service workflow. */
    private function snapshot(GoLiveWindow $window): array
    {
        $health = $this->health->snapshot(true);
        $launch = $this->launchGate->evaluate(null, false);
        $providerMaxAge = max(1, (int) config('vsn.go_live.provider_health_max_age_minutes', 15));
        $requiredProviders=$this->requiredProviders();
        $runtime=ProviderRuntimeStatus::query()->orderBy('provider_type')->orderBy('provider_code')->get()->keyBy(/** Inline callback for this operation. */ fn($p)=>$p->provider_type.'|'.$p->provider_code);
        $providers=collect($requiredProviders)->map(/** Inline callback for this operation. */ function($target)use($runtime,$providerMaxAge){$key=$target['type'].'|'.$target['code'];$p=$runtime->get($key);return [
            'type'=>$target['type'],'code'=>$target['code'],'required'=>true,'status'=>$p?->status??'missing','productionReady'=>(bool)($p?->production_ready??false),
            'checkedAt'=>$p?->checked_at?->toIso8601String(),'fresh'=>$p?->checked_at?->gte(now()->subMinutes($providerMaxAge))??false,'latencyMs'=>$p?->latency_ms,
        ];})->values()->all();
        $finance = FinanceReconciliationRun::query()->whereNotNull('completed_at')->latest('completed_at')->first();
        $financeMaxAge = max(5, (int) config('vsn.go_live.finance_reconciliation_max_age_minutes', 75));
        $financeFresh = $finance?->completed_at?->gte(now()->subMinutes($financeMaxAge)) ?? false;
        $critical = IncidentRecord::query()->whereIn('severity',['sev1','sev2'])->whereNotIn('status',['resolved','closed'])->count();
        $sev3 = IncidentRecord::query()->where('severity','sev3')->whereNotIn('status',['resolved','closed'])->count();
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $notificationFailed = NotificationDelivery::query()->where('status','failed')->count();
        $notificationPending = NotificationDelivery::query()->whereIn('status',['pending','processing'])->count();
        $activity = $this->activitySnapshot($window->opened_at ?: now());
        $latestReconciliations = ProviderReconciliationRun::query()->whereNotNull('completed_at')->orderByDesc('completed_at')->limit(20)->get()->groupBy(/** Inline callback for this operation. */ fn($r)=>$r->provider_type.'|'.$r->provider_code)->map(/** Inline callback for this operation. */ fn($g)=>$g->first())->values()->map(/** Inline callback for this operation. */ fn($r)=>[
            'type'=>$r->provider_type,'code'=>$r->provider_code,'status'=>$r->status,'mismatches'=>(int)$r->mismatch_count,'errors'=>(int)$r->error_count,'completedAt'=>$r->completed_at?->toIso8601String(),
        ])->all();
        $manifest = ReleaseCandidateManifest::query()->where('release',$window->release)->latest('sealed_at')->first();
        $identity = $manifest && $manifest->id === $window->release_candidate_manifest_id
            && $manifest->manifest_sha256 === $window->release_manifest_sha256 && $manifest->artifact_sha256 === $window->artifact_sha256;
        return [
            'release'=>$window->release,'observedRelease'=>(string)config('vsn.operations.release','unknown'),'releaseIdentityMatch'=>(bool)$identity,
            'health'=>$health,'launchGate'=>['ready'=>(bool)($launch['ready']??false),'blockers'=>(int)($launch['blockersCount']??0),'warnings'=>(int)($launch['warningsCount']??0)],
            'providers'=>$providers,'providerReconciliations'=>$latestReconciliations,
            'finance'=>['id'=>$finance?->public_id,'status'=>$finance?->status,'issuesCount'=>(int)($finance?->issues_count??0),'completedAt'=>$finance?->completed_at?->toIso8601String(),'fresh'=>$financeFresh,'maxAgeMinutes'=>$financeMaxAge],
            'incidents'=>['critical'=>$critical,'sev3'=>$sev3],
            'queues'=>['failedJobs'=>$failedJobs,'notificationFailed'=>$notificationFailed,'notificationPending'=>$notificationPending],
            'activity'=>$activity,
        ];
    }

    /** Handles activity snapshot for the go live stabilization service workflow. */
    private function activitySnapshot($since): array
    {
        $orders = Order::query()->where('placed_at','>=',$since);
        $orderCount = (clone $orders)->count();
        $gross = (int) (clone $orders)->sum('total_minor');
        $payments = PaymentIntent::query()->where('created_at','>=',$since);
        $attempts = (clone $payments)->whereIn('status',[PaymentIntentStatus::Paid->value,PaymentIntentStatus::Failed->value])->count();
        $failed = (clone $payments)->where('status',PaymentIntentStatus::Failed->value)->count();
        $paid = (clone $payments)->where('status',PaymentIntentStatus::Paid->value)->count();
        return ['orders'=>$orderCount,'grossMinor'=>$gross,'paymentAttempts'=>$attempts,'paidPayments'=>$paid,'failedPayments'=>$failed,'paymentFailurePercent'=>$attempts>0?round(($failed/$attempts)*100,2):0.0,'since'=>$since?->toIso8601String()];
    }

    /** Handles blockers for the go live stabilization service workflow. */
    private function blockers(array $s, GoLiveWindow $window): array
    {
        $t = $window->thresholds ?: $this->thresholds();
        $out=[];$add=/** Inline callback for this operation. */ function(string $code,string $message,array $details=[])use(&$out){$out[]=['code'=>$code,'message'=>$message,'details'=>$details];};
        if (!($s['releaseIdentityMatch']??false) || ($s['observedRelease']??'') !== $window->release) $add('release_identity','Running release/RC identity no longer matches the sealed candidate.');
        if (($s['health']['status']??'blocked') !== 'ready') $add('operational_health','Production readiness is not healthy.',['status'=>$s['health']['status']??null]);
        if (!(bool)($s['launchGate']['ready']??false)) $add('launch_gate','Technical launch gate is no longer healthy.',['blockers'=>$s['launchGate']['blockers']??null]);
        $badProviders=collect($s['providers']??[])->filter(/** Inline callback for this operation. */ fn($p)=>!($p['productionReady']??false)||($p['status']??'')!=='healthy'||!($p['fresh']??false))->values()->all();
        if($badProviders)$add('provider_health','One or more required provider health snapshots are unhealthy or stale.',['providers'=>$badProviders]);
        if(!($s['finance']['fresh']??false)||($s['finance']['status']??'')!=='clean'||(int)($s['finance']['issuesCount']??0)>0)$add('finance_reconciliation','Finance reconciliation is not recent and clean.',['finance'=>$s['finance']]);
        if((int)($s['incidents']['critical']??0)>0)$add('critical_incidents','An unresolved SEV1/SEV2 incident exists.',['count'=>$s['incidents']['critical']]);
        $failedJobs=$s['queues']['failedJobs']??null;if($failedJobs!==null&&(int)$failedJobs>(int)$t['maxFailedJobs'])$add('failed_jobs','Failed queue jobs exceed the stabilization threshold.',['count'=>$failedJobs,'max'=>$t['maxFailedJobs']]);
        if((int)($s['queues']['notificationFailed']??0)>(int)$t['maxNotificationFailed'])$add('notification_failures','Failed notification deliveries exceed the threshold.',['count'=>$s['queues']['notificationFailed'],'max'=>$t['maxNotificationFailed']]);
        if((int)($s['queues']['notificationPending']??0)>(int)$t['maxNotificationBacklog'])$add('notification_backlog','Notification delivery backlog exceeds the threshold.',['count'=>$s['queues']['notificationPending'],'max'=>$t['maxNotificationBacklog']]);
        $attempts=(int)($s['activity']['paymentAttempts']??0);$rate=(float)($s['activity']['paymentFailurePercent']??0);
        if($attempts>=(int)$t['paymentFailureMinimumAttempts']&&$rate>(float)$t['maxPaymentFailurePercent'])$add('payment_failure_rate','Payment failure rate exceeds the launch-window threshold.',['attempts'=>$attempts,'failurePercent'=>$rate,'maxPercent'=>$t['maxPaymentFailurePercent']]);
        return $out;
    }

    /** Handles warnings for the go live stabilization service workflow. */
    private function warnings(array $s, GoLiveWindow $window): array
    {
        $t=$window->thresholds ?: $this->thresholds();$out=[];
        if((int)($s['incidents']['sev3']??0)>(int)$t['maxSev3Incidents'])$out[]=['code'=>'sev3_incidents','message'=>'Open SEV3 incidents exceed the warning threshold.','details'=>['count'=>$s['incidents']['sev3'],'max'=>$t['maxSev3Incidents']]];
        if((int)($s['activity']['orders']??0)===0)$out[]=['code'=>'no_order_activity','message'=>'No customer order activity has been observed in this launch window yet.','details'=>[]];
        return $out;
    }

    /** Handles required providers for the go live stabilization service workflow. */
    private function requiredProviders():array
    {
        $targets=[];
        if((bool)config('vsn.payments.methods.card.enabled',false)){$code=(string)config('vsn.payments.methods.card.provider');$targets[]=['type'=>'payment','code'=>$code];$targets[]=['type'=>'payment_vault','code'=>$code];}
        foreach(collect(config('vsn.shipping_methods',[]))->where('enabled',true)->pluck('provider')->filter()->unique() as $code)$targets[]=['type'=>'shipping','code'=>(string)$code];
        if((bool)config('vsn.security.seller_payout_requires_phone',true))$targets[]=['type'=>'sms','code'=>(string)config('vsn.security.sms_provider','sandbox')];
        $email=(string)config('vsn.notifications.email_provider','laravel_mail');if($email!=='laravel_mail')$targets[]=['type'=>'email','code'=>$email];
        $kyc=(string)config('vsn.kyc.provider','manual');if((bool)config('vsn.security.seller_payout_requires_identity',true)&&$kyc!=='manual')$targets[]=['type'=>'kyc','code'=>$kyc];
        return collect($targets)->filter(/** Inline callback for this operation. */ fn($x)=>$x['code']!=='')->unique(/** Inline callback for this operation. */ fn($x)=>$x['type'].'|'.$x['code'])->values()->all();
    }

    /** Handles thresholds for the go live stabilization service workflow. */
    private function thresholds(): array
    {
        return [
            'rollbackWindowMinutes'=>max(15,(int)config('vsn.go_live.rollback_window_minutes',120)),
            'stabilizationMinutes'=>max(15,(int)config('vsn.go_live.stabilization_minutes',240)),
            'observationIntervalMinutes'=>max(1,(int)config('vsn.go_live.observation_interval_minutes',5)),
            'requiredHealthyObservations'=>max(2,(int)config('vsn.go_live.required_healthy_observations',6)),
            'maxFailedJobs'=>max(0,(int)config('vsn.go_live.max_failed_jobs',0)),
            'maxNotificationFailed'=>max(0,(int)config('vsn.go_live.max_notification_failed',0)),
            'maxNotificationBacklog'=>max(0,(int)config('vsn.go_live.max_notification_backlog',500)),
            'maxPaymentFailurePercent'=>max(0.0,(float)config('vsn.go_live.max_payment_failure_percent',10)),
            'paymentFailureMinimumAttempts'=>max(1,(int)config('vsn.go_live.payment_failure_minimum_attempts',10)),
            'maxSev3Incidents'=>max(0,(int)config('vsn.go_live.max_sev3_incidents',2)),
        ];
    }

    /** Handles authorize area for the go live stabilization service workflow. */
    private function authorizeArea(User $user,string $area):void
    {
        $role=$user->role instanceof UserRole?$user->role->value:(string)$user->role;
        $allowed=match($area){
            'finance'=>[UserRole::Finance->value,UserRole::Admin->value,UserRole::SuperAdmin->value],
            'business_owner'=>[UserRole::SuperAdmin->value],
            default=>[UserRole::Admin->value,UserRole::SuperAdmin->value],
        };
        abort_unless(in_array($role,$allowed,true),403);
    }
}
