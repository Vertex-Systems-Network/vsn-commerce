<?php
namespace App\Domain\Operations\Services;

use App\Domain\Payments\Services\PaymentGatewayManager;
use App\Domain\Providers\Services\ProviderRuntimeService;
use App\Domain\Security\Services\SmsProviderManager;
use App\Domain\Shipping\Services\ShippingProviderManager;
use App\Models\BackupRun;
use App\Models\IncidentRecord;
use App\Models\LaunchGateRun;
use App\Models\ProviderReconciliationRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Defines the LaunchGateService class and its project responsibilities. */
class LaunchGateService
{
    /** Initializes the LaunchGateService instance and its dependencies. */
    public function __construct(
        private readonly OperationalHealthService $health,
        private readonly DatabaseIndexAuditService $indexes,
        private readonly PaymentGatewayManager $payments,
        private readonly ShippingProviderManager $shipping,
        private readonly SmsProviderManager $sms,
        private readonly ProviderRuntimeService $providerRuntime,
        private readonly ProductionConfigurationAuditService $configurationAudit,
    ) {}

    /** Handles evaluate for the launch gate service workflow. */
    public function evaluate(?int $actorUserId = null, bool $persist = false): array
    {
        $checks = [];
        $production = app()->isProduction();
        $environment = app()->environment();

        $this->check($checks, 'application_key', filled(config('app.key')), 'block', 'APP_KEY is configured.');
        $this->check($checks, 'debug_disabled', ! $production || ! (bool) config('app.debug'), 'block', 'APP_DEBUG must be false in production.');
        $this->check($checks, 'app_url_https', ! $production || $this->isHttps((string) config('app.url')), 'block', 'APP_URL must use HTTPS in production.');
        $this->check($checks, 'frontend_url_https', ! $production || $this->isHttps((string) config('vsn.frontend_url')), 'block', 'FRONTEND_URL must use HTTPS in production.');

        $configuration = $this->configurationAudit->audit();
        foreach (($configuration['checks'] ?? []) as $configCheck) {
            $this->check($checks, 'configuration_'.($configCheck['name'] ?? 'unknown'), (bool)($configCheck['ok'] ?? false), $production ? 'block' : 'warning', (string)($configCheck['message'] ?? 'Production configuration check.'));
        }

        $health = $this->health->snapshot(true);
        foreach (['database','cache','storage','migrations'] as $name) {
            $ok = (bool) ($health['checks'][$name]['ok'] ?? false);
            $this->check($checks, 'health_'.$name, $ok, 'block', "{$name} readiness check must pass.", $health['checks'][$name] ?? []);
        }
        $redisOk = (bool) ($health['checks']['redis']['ok'] ?? false);
        $this->check($checks, 'health_redis', $redisOk, $production ? 'block' : 'warning', 'Redis readiness must pass in production.', $health['checks']['redis'] ?? []);
        foreach (['scheduler','queue_worker'] as $name) {
            $ok = (bool) ($health['checks'][$name]['ok'] ?? false);
            $severity = $production ? 'block' : 'warning';
            $this->check($checks, 'health_'.$name, $ok, $severity, "{$name} heartbeat must be fresh for launch.", $health['checks'][$name] ?? []);
        }
        if (config('vsn.operations.require_queue_pressure', false)) {
            $this->check($checks, 'health_queue_pressure', (bool)($health['checks']['queue_pressure']['ok'] ?? false), $production ? 'block' : 'warning', 'Monitored queue depth must remain below the configured launch threshold.', $health['checks']['queue_pressure'] ?? []);
        }
        $failedJobs = (int) ($health['failedJobs'] ?? 0);
        $this->check($checks, 'failed_jobs', $failedJobs <= (int) config('vsn.operations.launch.max_failed_jobs', 0), $production ? 'block' : 'warning', 'Failed queue jobs must be reviewed before launch.', ['count'=>$failedJobs]);

        try {
            $indexAudit = $this->indexes->execute();
            $this->check($checks, 'database_indexes', ! ($indexAudit['supported'] ?? false) || (bool) ($indexAudit['ok'] ?? false), 'block', 'Required hot-path database indexes must exist.', $indexAudit);
        } catch (\Throwable $e) {
            $this->check($checks, 'database_indexes', false, 'block', 'Database index audit could not run.', ['error'=>class_basename($e)]);
        }


        $this->providerChecks($checks, $production);
        $this->providerReconciliationChecks($checks, $production);
        try { $this->backupCheck($checks, $production); } catch (\Throwable $e) { $this->check($checks, 'verified_backup', false, 'block', 'Backup readiness could not be evaluated.', ['error'=>class_basename($e)]); }
        $this->verificationManifestCheck($checks, $production);
        $this->activeIncidentCheck($checks, $production);

        $riskMode = (string) config('vsn.risk.mode', 'observe');
        if ($production && $riskMode === 'observe') {
            $this->check($checks, 'risk_mode', true, 'warning', 'Risk engine is in observe mode; calibrate false positives before stricter enforcement.', ['mode'=>$riskMode]);
        } else {
            $this->check($checks, 'risk_mode', true, 'pass', 'Risk mode is explicitly configured.', ['mode'=>$riskMode]);
        }

        $blockers = collect($checks)->where('status', 'block')->count();
        $warnings = collect($checks)->where('status', 'warning')->count();
        $report = [
            'ready' => $blockers === 0,
            'status' => $blockers === 0 ? 'ready' : 'blocked',
            'environment' => $environment,
            'release' => (string) config('vsn.operations.release', 'unknown'),
            'blockersCount' => $blockers,
            'warningsCount' => $warnings,
            'checkedAt' => now()->toIso8601String(),
            'checks' => $checks,
        ];

        if ($persist) {
            try {
                if (! Schema::hasTable('launch_gate_runs')) return $report;
                $run = LaunchGateRun::query()->create([
                'public_id' => (string) Str::ulid(),
                'actor_user_id' => $actorUserId,
                'environment' => $environment,
                'release' => $report['release'],
                'status' => $report['status'],
                'blockers_count' => $blockers,
                'warnings_count' => $warnings,
                'checks' => $checks,
                'ran_at' => now(),
                ]);
                $report['id'] = $run->public_id;
            } catch (\Throwable) {
                // A launch-gate evaluation remains useful even when audit persistence is unavailable.
            }
        }

        return $report;
    }

    /** Handles provider checks for the launch gate service workflow. */
    private function providerChecks(array &$checks, bool $production): void
    {
        $maxAge=(int)config('vsn.providers.health_max_age_minutes',15);
        $fresh=/** Inline callback for this operation. */ function(string $type,string $code)use($production,$maxAge):bool{return ! $production || (Schema::hasTable('provider_runtime_statuses') && $this->providerRuntime->freshHealthy($type,$code,$maxAge));};

        if ((bool) config('vsn.payments.methods.card.enabled', false)) {
            $provider=(string)config('vsn.payments.methods.card.provider');$registered=true;try{$this->payments->gateway($provider);}catch(\Throwable){$registered=false;}
            $ok=$registered&&!($production&&$provider==='sandbox')&&$fresh('payment',$provider);
            $this->check($checks,'payment_provider',$ok,'block','Enabled card payments require a registered, recently probed production gateway.',['provider'=>$provider,'healthMaxAgeMinutes'=>$maxAge]);
            $this->check($checks,'payment_vault_provider',$registered&&$fresh('payment_vault',$provider),'block','Saved-card vault must be healthy for the configured card provider.',['provider'=>$provider]);
        } else $this->check($checks,'payment_provider',true,'warning','Card payments are disabled; COD/Coins can launch only if this matches the business plan.');

        foreach(['standard','express'] as $method){if(!(bool)config("vsn.shipping_methods.{$method}.enabled",false))continue;$provider=(string)config("vsn.shipping_methods.{$method}.provider");$registered=true;try{$this->shipping->driver($provider);}catch(\Throwable){$registered=false;}$ok=$registered&&!($production&&$provider==='sandbox')&&$fresh('shipping',$provider);$this->check($checks,'shipping_'.$method,$ok,'block',ucfirst($method).' shipping requires a registered, recently probed production provider.',['provider'=>$provider,'healthMaxAgeMinutes'=>$maxAge]);}

        $smsName='unknown';try{$smsName=$this->sms->provider()->name();}catch(\Throwable){}$requiresPhone=(bool)config('vsn.security.seller_payout_requires_phone',true);$smsOk=!$production||!$requiresPhone||($smsName!=='sandbox'&&$fresh('sms',$smsName));$this->check($checks,'sms_provider',$smsOk,$requiresPhone?'block':'warning','Phone verification requires a recently probed real SMS provider before production payouts.',['provider'=>$smsName]);

        $emailProvider=(string)config('vsn.notifications.email_provider','laravel_mail');$mail=(string)config('mail.default','log');$frameworkMailOk=!$production||!in_array($mail,['log','array'],true);$emailProbeOk=!$production||($emailProvider==='laravel_mail'?$frameworkMailOk:$fresh('email',$emailProvider));$this->check($checks,'mail_provider',$frameworkMailOk&&$emailProbeOk,$production?'block':'warning','Production transactional email and framework auth mail must use configured delivery infrastructure.',['mailer'=>$mail,'provider'=>$emailProvider]);

        $requiresIdentity=(bool)config('vsn.security.seller_payout_requires_identity',true);$kyc=(string)config('vsn.kyc.provider','manual');$kycOk=!$production||!$requiresIdentity||($kyc!=='manual'&&$fresh('kyc',$kyc));$this->check($checks,'kyc_provider',$kycOk,$requiresIdentity?'block':'warning','Production identity verification requires a recently probed external KYC provider when seller payout KYC is mandatory.',['provider'=>$kyc]);
    }

    /** Handles provider reconciliation checks for the launch gate service workflow. */
    private function providerReconciliationChecks(array &$checks, bool $production): void
    {
        if (! $production) return;
        $maxAge=max(1,(int)config('vsn.providers.reconciliation_max_age_hours',24));
        $targets=[];
        if((bool)config('vsn.payments.methods.card.enabled',false))$targets[]=['payment',(string)config('vsn.payments.methods.card.provider')];
        foreach(collect(config('vsn.shipping_methods',[]))->where('enabled',true)->pluck('provider')->filter()->unique() as $provider)$targets[]=['shipping',(string)$provider];
        if((bool)config('vsn.security.seller_payout_requires_identity',true)&&($provider=(string)config('vsn.kyc.provider','manual'))!=='manual')$targets[]=['kyc',$provider];
        foreach(collect($targets)->unique(/** Inline callback for this operation. */ fn($x)=>$x[0].'|'.$x[1])->values() as [$type,$code]){
            $run=Schema::hasTable('provider_reconciliation_runs')?ProviderReconciliationRun::query()->where('provider_type',$type)->where('provider_code',$code)->whereNotNull('completed_at')->latest('completed_at')->first():null;
            $fresh=$run?->completed_at?->gte(now()->subHours($maxAge))??false;
            $clean=$run&&$run->status==='completed'&&$run->mismatch_count===0&&$run->error_count===0;
            $this->check($checks,'provider_reconciliation_'.$type.'_'.$code,(bool)($fresh&&$clean),'block','A recent clean live-provider reconciliation is required before production launch.',['providerType'=>$type,'provider'=>$code,'maxAgeHours'=>$maxAge,'status'=>$run?->status,'completedAt'=>$run?->completed_at?->toIso8601String(),'checked'=>$run?->checked_count,'mismatches'=>$run?->mismatch_count,'errors'=>$run?->error_count]);
        }
    }

    /** Handles backup check for the launch gate service workflow. */
    private function backupCheck(array &$checks, bool $production): void
    {
        $enabled = (bool) config('vsn.operations.backups.enabled', false);
        if (! $enabled) {
            $this->check($checks, 'verified_backup', ! $production, $production ? 'block' : 'warning', 'Production requires scheduled private PostgreSQL backups and a verified recent artifact.');
            return;
        }
        if (! Schema::hasTable('backup_runs')) {
            $this->check($checks, 'verified_backup', false, 'block', 'Backup audit table is unavailable.');
            return;
        }
        $latest = BackupRun::query()->where('status','completed')->whereNotNull('verified_at')->latest('verified_at')->first();
        $maxAge = max(1, (int) config('vsn.operations.launch.max_verified_backup_age_hours', 30));
        $fresh = $latest?->verified_at && $latest->verified_at->gte(now()->subHours($maxAge));
        $this->check($checks, 'verified_backup', (bool) $fresh, $production ? 'block' : 'warning', 'A recent checksum-verified database backup is required.', [
            'maxAgeHours'=>$maxAge,
            'verifiedAt'=>$latest?->verified_at?->toIso8601String(),
        ]);
    }

    /** Handles verification manifest check for the launch gate service workflow. */
    private function verificationManifestCheck(array &$checks, bool $production): void
    {
        $required = (bool) config('vsn.operations.launch.require_verification_manifest', false);
        $path = (string) config('vsn.operations.launch.verification_manifest');
        if (! File::exists($path)) {
            $this->check($checks, 'runtime_verification', ! $required, $production && $required ? 'block' : 'warning', 'Runtime verification manifest is missing.', ['path'=>basename($path)]);
            return;
        }
        $json = json_decode((string) File::get($path), true);
        $requiredFlags = ['composerLock','npmLock','dependencies','databaseMigrations','laravelTests','frontendBuild','appSmoke','authenticatedE2E','queueHeartbeat','schedulerHeartbeat','backupRestoreDrill','providerContracts'];
        $missing = collect($requiredFlags)->filter(/** Inline callback for this operation. */ fn(string $key) => ($json[$key] ?? false) !== true)->values()->all();
        $this->check($checks, 'runtime_verification', $missing === [], $production && $required ? 'block' : 'warning', 'Runtime integration suite must pass before launch.', ['missing'=>$missing,'generatedAt'=>$json['generatedAt'] ?? null]);
    }


    /** Handles active incident check for the launch gate service workflow. */
    private function activeIncidentCheck(array &$checks, bool $production): void
    {
        if (! Schema::hasTable('incident_records')) {
            $this->check($checks, 'active_incidents', ! $production, $production ? 'block' : 'warning', 'Incident registry is unavailable.');
            return;
        }
        $active = IncidentRecord::query()->where('status', '!=', 'resolved')->whereIn('severity', ['sev1','sev2'])->latest('started_at')->limit(20)->get(['public_id','severity','status','title','started_at']);
        $this->check($checks, 'active_incidents', $active->isEmpty(), $production ? 'block' : 'warning', 'No unresolved SEV1/SEV2 incident may exist during a production release.', [
            'count'=>$active->count(),
            'incidents'=>$active->map(/** Inline callback for this operation. */ fn($i)=>['id'=>$i->public_id,'severity'=>$i->severity,'status'=>$i->status,'title'=>$i->title,'startedAt'=>$i->started_at?->toIso8601String()])->all(),
        ]);
    }

    /** Handles check for the launch gate service workflow. */
    private function check(array &$checks, string $code, bool $ok, string $failureSeverity, string $message, array $details = []): void
    {
        $checks[] = ['code'=>$code,'status'=>$ok?'pass':$failureSeverity,'message'=>$message,'details'=>$details];
    }

    /** Handles is https for the launch gate service workflow. */
    private function isHttps(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
