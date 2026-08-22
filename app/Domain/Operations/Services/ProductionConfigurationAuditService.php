<?php
namespace App\Domain\Operations\Services;

use Illuminate\Support\Facades\File;

/** Defines the ProductionConfigurationAuditService class and its project responsibilities. */
class ProductionConfigurationAuditService
{
    /** Handles audit for the production configuration audit service workflow. */
    public function audit(): array
    {
        $checks=[];
        $prod=app()->isProduction();
        $this->add($checks,'environment',!$prod || app()->environment()==='production','APP_ENV must be production for a production release.');
        $this->add($checks,'debug_disabled',!$prod || !config('app.debug'),'APP_DEBUG must be false.');
        $this->add($checks,'app_key',filled(config('app.key')),'APP_KEY must be configured.');
        $this->add($checks,'https_url',!$prod || str_starts_with((string)config('app.url'),'https://'),'APP_URL must use HTTPS.');
        $this->add($checks,'demo_disabled',!$prod || !config('vsn.demo.enabled'),'Demo seed mode must be disabled.');
        $this->add($checks,'redis_cache',!$prod || config('cache.default')==='redis','Production cache store should be Redis.');
        $this->add($checks,'redis_queue',!$prod || config('queue.default')==='redis','Production queue connection should be Redis.');
        $this->add($checks,'session_encrypted',!$prod || (bool)config('session.encrypt'),'Production session payloads must be encrypted.');
        $this->add($checks,'secure_session_cookie',!$prod || (bool)config('session.secure'),'Production session cookies must be HTTPS-only.');
        $this->add($checks,'session_same_site',!$prod || in_array((string)config('session.same_site'),['lax','strict'],true),'Production session SameSite must be lax or strict.');
        $this->add($checks,'scheduler_required',!$prod || config('vsn.operations.require_scheduler'),'Scheduler heartbeat must be required.');
        $this->add($checks,'worker_required',!$prod || config('vsn.operations.require_queue_worker'),'Queue-worker heartbeat must be required.');
        $this->add($checks,'backups_enabled',!$prod || config('vsn.operations.backups.enabled'),'Database backups must be enabled.');
        $this->add($checks,'backup_private_disk',!$prod || !in_array((string)config('vsn.operations.backups.disk'),['public'],true),'Production backup disk must be private.');
        $this->add($checks,'release_named',filled(config('vsn.operations.release')) && config('vsn.operations.release')!=='unknown','VSN_RELEASE must identify the deployed release.');
        $goLiveSignoffs=(array)config('vsn.go_live.required_signoffs',[]);
        $this->add($checks,'go_live_signoffs',!$prod || collect(['operations','finance','business_owner'])->every(/** Inline callback for this operation. */ fn($x)=>in_array($x,$goLiveSignoffs,true)),'Production stabilization requires Operations, Finance and Business Owner approvals.');
        $this->add($checks,'go_live_distinct_signers',!$prod || (bool)config('vsn.go_live.require_distinct_signers',false),'Production stabilization must require distinct authorized signers.');
        $this->add($checks,'go_live_stabilization_window',!$prod || (int)config('vsn.go_live.stabilization_minutes',0)>=60,'Production stabilization window must be at least 60 minutes.');
        $this->add($checks,'go_live_rollback_window',!$prod || (int)config('vsn.go_live.rollback_window_minutes',0)>=30,'Production rollback window must be at least 30 minutes.');
        $this->add($checks,'go_live_auto_incident',!$prod || (bool)config('vsn.go_live.auto_open_incident',false),'Production go-live blockers must automatically open stabilization incident evidence.');
        $this->add($checks,'composer_lock',File::exists(base_path('composer.lock')),'composer.lock is required for reproducible production dependencies.');
        $this->add($checks,'npm_lock',File::exists(base_path('package-lock.json')),'package-lock.json is required for reproducible frontend dependencies.');
        $this->add($checks,'sandbox_payment_disabled',!$prod || !config('vsn.payments.providers.sandbox.simulator_enabled',false),'Sandbox payment simulator must be disabled.');
        $this->add($checks,'sandbox_shipping_disabled',!$prod || !config('vsn.shipping.providers.sandbox.simulator_enabled',false),'Sandbox shipping simulator must be disabled.');
        $this->add($checks,'sandbox_sms_disabled',!$prod || !config('vsn.security.sandbox_sms_enabled',false),'Sandbox SMS must be disabled.');
        $this->add($checks,'csp_enabled',!$prod || config('vsn.security.csp.enabled',true),'Production content security policy must be enabled.');
        $this->add($checks,'distinct_acceptance_signers',!$prod || (bool)config('vsn.acceptance.require_distinct_signers',false),'Production final acceptance must require distinct signers.');
        $this->add($checks,'final_acceptance_manifest_path',filled(config('vsn.acceptance.verification_manifest')),'Final acceptance verification manifest path must be configured.');
        $this->add($checks,'release_candidate_manifest_path',filled(config('vsn.acceptance.release_candidate_manifest_path')),'Final release-candidate manifest path must be configured.');
        $blockers=collect($checks)->where('ok',false)->count();
        return ['ok'=>$blockers===0,'blockersCount'=>$blockers,'checks'=>$checks,'checkedAt'=>now()->toIso8601String()];
    }
    /** Handles add for the production configuration audit service workflow. */
    private function add(array &$checks,string $name,bool $ok,string $message):void{$checks[]=['name'=>$name,'ok'=>$ok,'message'=>$message];}
}
