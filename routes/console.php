<?php
use App\Domain\Operations\Services\HeartbeatService;
use App\Domain\Operations\Services\DatabaseIndexAuditService;
use App\Domain\Operations\Services\DatabaseBackupService;
use App\Domain\Operations\Services\LaunchGateService;
use App\Jobs\CreateDatabaseBackupJob;
use App\Jobs\DispatchNotificationDeliveriesJob;
use App\Jobs\GenerateReportExportsJob;
use App\Jobs\QueueHeartbeatJob;
use App\Jobs\ReconcileProductAlertsJob;
use App\Jobs\ReconcileRiskProfilesJob;

use App\Domain\Affiliate\Actions\AccrueAffiliateCommissions;
use App\Domain\Affiliate\Actions\CreditAvailableAffiliateCommissions;
use App\Domain\Affiliate\Actions\MatureAffiliateCommissions;
use App\Domain\Wallet\Services\CoinLotService;
use App\Domain\Checkout\Actions\ReleaseCheckoutSession;
use App\Domain\Games\Actions\AdvanceGameLifecycle;
use App\Domain\Games\Actions\RefundCancelledGameEntries;
use App\Domain\Gifts\Actions\DispatchGiftNotifications;
use App\Domain\Gifts\Actions\CancelGiftCheckout;
use App\Domain\Gifts\Actions\FinalizeGiftOrder;
use App\Domain\Gifts\Actions\ReconcileGiftStatuses;
use App\Domain\Finance\Actions\PostOrderFinance;
use App\Domain\Tax\Actions\IssueOrderInvoices;
use App\Domain\Finance\Actions\ReconcileVendorSettlements;
use App\Domain\Finance\Actions\RunFinanceReconciliation;

use App\Domain\Reviews\Actions\DispatchReviewReminders;
use App\Domain\Reviews\Actions\ExpireReviewCoupons;
use App\Domain\Shipping\Actions\CheckShippingSlas;
use App\Domain\Notifications\Actions\ReconcileMarketplaceNotifications;
use App\Domain\Notifications\Actions\DispatchNotificationDeliveries;
use App\Domain\Kyc\Services\KycLifecycleService;
use App\Domain\Catalog\Actions\EvaluateProductAlerts;
use App\Enums\CheckoutStatus;
use App\Enums\GiftStatus;
use App\Models\CheckoutSession;
use App\Models\Gift;
use App\Models\Order;
use App\Enums\PaymentStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Domain\Risk\Actions\ReconcileRiskProfiles;

Artisan::command('vsn:expire-checkouts', /** Inline callback for this operation. */ function (): void {
    $released = 0;
    $action = app(ReleaseCheckoutSession::class);

    CheckoutSession::query()
        ->where('status', CheckoutStatus::Reserved->value)
        ->where('expires_at', '<=', now())
        ->orderBy('id')
        ->chunkById(100, /** Inline callback for this operation. */ function ($sessions) use ($action, &$released): void {
            foreach ($sessions as $session) {
                $action->execute($session, CheckoutStatus::Expired);
                $released++;
            }
        });

    $this->info("Expired checkout sessions released: {$released}");
})->purpose('Release inventory held by expired checkout sessions.');

Schedule::command('vsn:expire-checkouts')->everyMinute()->withoutOverlapping();
Schedule::command('model:prune')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();

Artisan::command('vsn:mobile-sessions-prune', /** Inline callback for this operation. */ function (): void {
    $deleted = \App\Models\MobileApiSession::query()
        ->where(/** Inline callback for this operation. */ function ($query): void {
            $query->whereNotNull('revoked_at')->orWhere('refresh_expires_at', '<', now()->subDays(30));
        })
        ->where('updated_at', '<', now()->subDays(30))
        ->delete();
    $oauthDeleted = \App\Models\MobileOAuthFlow::query()->where('expires_at', '<', now()->subDay())->delete();
    $this->info("Old mobile API sessions pruned: {$deleted}; expired mobile OAuth flows pruned: {$oauthDeleted}");
})->purpose('Remove old revoked/expired Android API session metadata and expired one-time OAuth flow records.');
Schedule::command('vsn:mobile-sessions-prune')->dailyAt('03:40')->withoutOverlapping();

Artisan::command('vsn:affiliate-accrue', /** Inline callback for this operation. */ function (): void {
    $action = app(AccrueAffiliateCommissions::class);
    $processed = 0;
    Order::query()
        ->where('payment_status', PaymentStatus::Paid->value)
        ->whereNull('affiliate_accrued_at')
        ->orderBy('id')
        ->chunkById(100, /** Inline callback for this operation. */ function ($orders) use ($action, &$processed): void {
            foreach ($orders as $order) {
                $action->execute($order);
                $processed++;
            }
        });
    $this->info("Paid orders checked for affiliate accrual: {$processed}");
})->purpose('Idempotently accrue affiliate commissions for paid orders.');

Artisan::command('vsn:affiliate-mature', /** Inline callback for this operation. */ function (): void {
    $matured = app(MatureAffiliateCommissions::class)->execute();
    $credited = app(CreditAvailableAffiliateCommissions::class)->execute();
    $this->info("Affiliate commissions matured: {$matured}; wallet credits posted: {$credited}");
})->purpose('Mature affiliate commissions after the hold window and credit VSN Coins.');

Schedule::command('vsn:affiliate-accrue')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('vsn:affiliate-mature')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('vsn:wallet-expire', /** Inline callback for this operation. */ function (): void {
    $processed = app(CoinLotService::class)->expireDue((int) config('vsn.wallet.expiry_batch_size', 500));
    $this->info("Expired VSN Coin lots processed: {$processed}");
})->purpose('Expire due promotional VSN Coin lots using immutable wallet ledger debits.');

Schedule::command('vsn:wallet-expire')->hourly()->withoutOverlapping();



Artisan::command('vsn:games-lifecycle', /** Inline callback for this operation. */ function (): void {
    $result = app(AdvanceGameLifecycle::class)->execute();
    $this->info("Games opened: {$result['opened']}; closed: {$result['closed']}; drawn: {$result['drawn']}; cancelled empty: {$result['cancelled']}");
})->purpose('Open/close Game Win campaigns on schedule and draw due winners.');

Artisan::command('vsn:game-refunds', /** Inline callback for this operation. */ function (): void {
    $processed = app(RefundCancelledGameEntries::class)->execute(null, (int) config('vsn.games.refund_batch_size', 200));
    $this->info("Cancelled Game Win entries refunded: {$processed}");
})->purpose('Process idempotent VSN Coin refunds for cancelled Game Win entries.');

Schedule::command('vsn:games-lifecycle')->everyMinute()->withoutOverlapping();
Schedule::command('vsn:game-refunds')->everyMinute()->withoutOverlapping();


Artisan::command('vsn:gifts-reconcile', /** Inline callback for this operation. */ function (): void {
    $finalize = app(FinalizeGiftOrder::class);
    $cancel = app(CancelGiftCheckout::class);
    $processed = 0;
    $released = 0;
    Order::query()
        ->where('payment_status', PaymentStatus::Paid->value)
        ->whereHas('gift', /** Inline callback for this operation. */ fn ($query) => $query->whereNull('progress_recorded_at'))
        ->with('gift')
        ->orderBy('id')
        ->chunkById(100, /** Inline callback for this operation. */ function ($orders) use ($finalize, &$processed): void {
            foreach ($orders as $order) { $finalize->execute($order); $processed++; }
        });

    Gift::query()
        ->where('status', GiftStatus::AwaitingPayment->value)
        ->whereNull('order_id')
        ->whereHas('checkoutSession', /** Inline callback for this operation. */ fn ($query) => $query->whereIn('status', [CheckoutStatus::Expired->value, CheckoutStatus::Cancelled->value]))
        ->with(['sender','checkoutSession'])
        ->orderBy('id')
        ->chunkById(100, /** Inline callback for this operation. */ function ($gifts) use ($cancel, &$released): void {
            foreach ($gifts as $gift) { $cancel->execute($gift->sender, $gift); $released++; }
        });

    $statusChanges = app(ReconcileGiftStatuses::class)->execute();
    $this->info("Paid gift orders reconciled: {$processed}; abandoned gift rewards released: {$released}; gift status changes: {$statusChanges}");
})->purpose('Finalize paid gifts and release reward reservations from expired/cancelled gift checkouts.');

Artisan::command('vsn:gift-notifications', /** Inline callback for this operation. */ function (): void {
    $processed = app(DispatchGiftNotifications::class)->execute();
    $this->info("Gift notifications released: {$processed}");
})->purpose('Release scheduled in-app gift notifications without exposing recipient addresses.');

Schedule::command('vsn:gifts-reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('vsn:gift-notifications')->everyMinute()->withoutOverlapping();


Artisan::command('vsn:review-reminders', /** Inline callback for this operation. */ function (): void {
    $processed = app(DispatchReviewReminders::class)->execute();
    $this->info("Verified-purchase review reminders queued: {$processed}");
})->purpose('Queue one review reminder per eligible delivered order item.');

Schedule::command('vsn:review-reminders')->hourly()->withoutOverlapping();


Artisan::command('vsn:review-coupons-expire', /** Inline callback for this operation. */ function (): void {
    $expired = app(ExpireReviewCoupons::class)->execute();
    $this->info("Expired unused review coupons: {$expired}");
})->purpose('Expire unused verified-review coupons after their configured validity window.');

Schedule::command('vsn:review-coupons-expire')->hourly()->withoutOverlapping();

Artisan::command('vsn:search-prune', /** Inline callback for this operation. */ function (): void {
    $days=max(1,(int)config('vsn.catalog.search_history_retention_days',90));
    $deleted=\App\Models\CatalogSearchEvent::query()->where('searched_at','<',now()->subDays($days))->delete();
    $this->info("Catalog search events pruned: {$deleted}");
})->purpose('Prune bounded customer/visitor catalog search history.');
Schedule::command('vsn:search-prune')->dailyAt('03:25')->withoutOverlapping();


Artisan::command('vsn:finance-post-orders', /** Inline callback for this operation. */ function (): void {
    $action=app(PostOrderFinance::class);$count=0;
    Order::query()->whereHas('vendorOrders',/** Inline callback for this operation. */ fn($q)=>$q->whereNull('finance_posted_at'))->with('vendorOrders')->orderBy('id')->chunkById(100,/** Inline callback for this operation. */ function($orders)use($action,&$count):void{foreach($orders as $order){$action->execute($order);$count++;}});
    $this->info("Orders posted to finance ledger: {$count}");
})->purpose('Backfill idempotent finance journals and seller settlements for orders.');

Artisan::command('vsn:vendor-settlements', /** Inline callback for this operation. */ function (): void {
    $count=app(ReconcileVendorSettlements::class)->execute();$this->info("Vendor settlements reconciled: {$count}");
})->purpose('Move seller settlements through payment, delivery and return-window holds.');

Artisan::command('vsn:finance-reconcile', /** Inline callback for this operation. */ function (): void {
    $run=app(RunFinanceReconciliation::class)->execute(null,true);$this->info("Finance reconciliation {$run->public_id}: {$run->status}; issues {$run->issues_count}");
})->purpose('Backfill and verify immutable finance journals, settlements and payouts.');

Schedule::command('vsn:finance-post-orders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('vsn:vendor-settlements')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('vsn:finance-reconcile')->hourly()->withoutOverlapping();


Artisan::command('vsn:shipping-sla', /** Inline callback for this operation. */ function (): void {
    $result=app(CheckShippingSlas::class)->execute();
    $this->info("Shipping SLA breaches marked — dispatch: {$result['dispatchBreaches']}; delivery: {$result['deliveryBreaches']}");
})->purpose('Mark overdue seller dispatch and customer delivery SLAs without mutating shipment event history.');

Schedule::command('vsn:shipping-sla')->everyFiveMinutes()->withoutOverlapping();


Artisan::command('vsn:notifications-reconcile', /** Inline callback for this operation. */ function (): void {
    $result=app(ReconcileMarketplaceNotifications::class)->execute();
    $this->info('Notification source facts reconciled: '.json_encode($result));
})->purpose('Create missing deduplicated customer notifications from authoritative marketplace events.');

Artisan::command('vsn:notifications-dispatch', /** Inline callback for this operation. */ function (): void {
    $result=app(DispatchNotificationDeliveries::class)->execute((int)config('vsn.notifications.delivery_batch_size',200));
    $this->info('Notification deliveries: '.json_encode($result));
})->purpose('Dispatch pending notification channel deliveries with retry/backoff.');


Artisan::command('vsn:kyc-lifecycle', /** Inline callback for this operation. */ function (): void {
    $result=app(KycLifecycleService::class)->reconcile((int)config('vsn.kyc.retry_batch_size',100));
    $this->info('KYC lifecycle: '.json_encode($result));
})->purpose('Expire stale KYC approvals and retry due provider submissions.');
Schedule::command('vsn:kyc-lifecycle')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('vsn:notifications-reconcile')->everyMinute()->withoutOverlapping();
if (config('vsn.operations.async_scheduler')) Schedule::job(new DispatchNotificationDeliveriesJob)->everyMinute(); else Schedule::command('vsn:notifications-dispatch')->everyMinute()->withoutOverlapping();



Artisan::command('vsn:product-view-prune', /** Inline callback for this operation. */ function (): void {
    $days=max(30,(int)config('vsn.catalog.product_view_retention_days',180));
    $deleted=\App\Models\ProductView::query()->where('viewed_at','<',now()->subDays($days))->delete();
    $this->info("Old product-view records pruned: {$deleted}");
})->purpose('Delete product-view personalization/analytics records outside the configured retention window.');
Schedule::command('vsn:product-view-prune')->dailyAt('03:25')->withoutOverlapping();

Artisan::command('vsn:product-alerts', /** Inline callback for this operation. */ function (): void {
    $result=app(EvaluateProductAlerts::class)->execute(null,1000);
    $this->info("Product alerts checked: {$result['checked']}; triggered: {$result['triggered']}");
})->purpose('Reconcile price-drop and back-in-stock alerts from authoritative catalog and inventory state.');

if (config('vsn.operations.async_scheduler')) Schedule::job(new ReconcileProductAlertsJob)->everyFiveMinutes(); else Schedule::command('vsn:product-alerts')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('vsn:promotions-reconcile', /** Inline callback for this operation. */ function (): void {
    $ended=\App\Models\Promotion::query()
        ->where('status','active')
        ->whereNotNull('ends_at')
        ->where('ends_at','<=',now())
        ->update(['status'=>'ended','updated_at'=>now()]);
    $this->info("Promotions marked ended: {$ended}");
})->purpose('Close promotions whose configured end time has passed; checkout usage reservations are released by checkout expiry/cancellation.');

Schedule::command('vsn:promotions-reconcile')->everyFiveMinutes()->withoutOverlapping();


Artisan::command('vsn:tax-invoices-reconcile', /** Inline callback for this operation. */ function (): void {
    $action=app(IssueOrderInvoices::class);$count=0;
    Order::query()->whereDoesntHave('taxInvoices')->with(['vendorOrders','shippingAddress'])->orderBy('id')->chunkById(100,/** Inline callback for this operation. */ function($orders)use($action,&$count):void{foreach($orders as $order){$action->execute($order);$count++;}});
    $this->info("Orders checked for missing tax invoices: {$count}");
})->purpose('Idempotently issue missing immutable seller tax invoices from frozen order tax snapshots.');
Schedule::command('vsn:tax-invoices-reconcile')->everyFiveMinutes()->withoutOverlapping();


Artisan::command('vsn:risk-reconcile', /** Inline callback for this operation. */ function (): void {
    $result=app(ReconcileRiskProfiles::class)->execute((int)config('vsn.risk.reconcile_limit',500));
    $expired=\App\Models\RiskHold::query()->where('status','active')->whereNotNull('expires_at')->where('expires_at','<=',now())->update(['status'=>'expired']);
    $this->info("Risk profiles evaluated — users: {$result['users']}; vendors: {$result['vendors']}; holds expired: {$expired}");
})->purpose('Recalculate fraud/abuse evidence profiles and expire time-limited risk holds.');
if (config('vsn.operations.async_scheduler')) Schedule::job(new ReconcileRiskProfilesJob)->everyFiveMinutes(); else Schedule::command('vsn:risk-reconcile')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('vsn:reports-schedule', /** Inline callback for this operation. */ function (): void {
    $queued=app(\App\Domain\Reporting\Actions\RunScheduledReports::class)->execute((int)config('vsn.reporting.schedule_batch_size',100));
    $this->info("Scheduled report exports queued: {$queued}");
})->purpose('Queue due private BI/report exports owned by authorized Finance/Admin users.');

Artisan::command('vsn:reports-generate', /** Inline callback for this operation. */ function (): void {
    $processed=app(\App\Domain\Reporting\Actions\ProcessQueuedReportExports::class)->execute();
    $this->info("Queued report exports processed: {$processed}");
})->purpose('Generate queued CSV reports on private storage with checksum and spreadsheet-injection protection.');

Artisan::command('vsn:reports-expire', /** Inline callback for this operation. */ function (): void {
    $expired=app(\App\Domain\Reporting\Actions\ExpireReportExports::class)->execute();
    $this->info("Expired private report files removed: {$expired}");
})->purpose('Delete expired private export files while retaining non-sensitive audit metadata.');

Schedule::command('vsn:reports-schedule')->everyMinute()->withoutOverlapping();
if (config('vsn.operations.async_scheduler')) Schedule::job(new GenerateReportExportsJob)->everyMinute(); else Schedule::command('vsn:reports-generate')->everyMinute()->withoutOverlapping();
Schedule::command('vsn:reports-expire')->hourly()->withoutOverlapping();


Artisan::command('vsn:operations-heartbeat', /** Inline callback for this operation. */ function (HeartbeatService $heartbeats): void {
    $heartbeats->beat('scheduler', ['release'=>config('vsn.operations.release')]);
    QueueHeartbeatJob::dispatch();
    $this->info('Scheduler heartbeat recorded and queue-worker heartbeat dispatched.');
})->purpose('Record scheduler liveness and dispatch a queue-worker heartbeat probe.');
Schedule::command('vsn:operations-heartbeat')->everyMinute()->withoutOverlapping();

Artisan::command('vsn:production-config-audit', /** Inline callback for this operation. */ function (\App\Domain\Operations\Services\ProductionConfigurationAuditService $audit): int {
    $report=$audit->audit();
    $this->line(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    if(!$report['ok']){$this->error("Production configuration blocked by {$report['blockersCount']} check(s).");return 1;}
    $this->info('Production configuration checks passed.');return 0;
})->purpose('Validate release-critical environment, queue/cache, backup, demo and sandbox settings.');

Artisan::command('vsn:deploy-begin {--release=} {--previous=} {--commit=} {--artifact=} {--composer-lock=} {--npm-lock=} {--backup=} {--maintenance}', /** Inline callback for this operation. */ function (\App\Domain\Operations\Services\DeploymentService $service): int {
    $backup=null;if($this->option('backup'))$backup=\App\Models\BackupRun::query()->where('public_id',(string)$this->option('backup'))->firstOrFail();
    $run=$service->begin(['release'=>$this->option('release')?:config('vsn.operations.release'),'previous_release'=>$this->option('previous')?:null,'commit_sha'=>$this->option('commit')?:null,'artifact_sha256'=>$this->option('artifact')?:null,'composer_lock_sha256'=>$this->option('composer-lock')?:null,'npm_lock_sha256'=>$this->option('npm-lock')?:null,'backup_run_id'=>$backup?->id,'maintenance_used'=>(bool)$this->option('maintenance')]);
    if($backup)$service->attachBackup($run,$backup);
    $this->line((string)$run->public_id);return 0;
})->purpose('Begin an auditable production deployment record and print its public deployment ID.');

Artisan::command('vsn:deploy-phase {deployment} {phase}', /** Inline callback for this operation. */ function (string $deployment,string $phase,\App\Domain\Operations\Services\DeploymentService $service): void {
    $run=\App\Models\DeploymentRun::query()->where('public_id',$deployment)->firstOrFail();$run=$service->phase($run,$phase);$this->info("Deployment {$run->public_id}: {$run->phase}.");
})->purpose('Advance a running deployment to a named auditable phase.');

Artisan::command('vsn:deploy-complete {deployment}', /** Inline callback for this operation. */ function (string $deployment,\App\Domain\Operations\Services\DeploymentService $service): void {
    $run=\App\Models\DeploymentRun::query()->where('public_id',$deployment)->firstOrFail();$run=$service->complete($run,['launchGate'=>'operator_confirmed']);$this->info("Deployment {$run->public_id} completed.");
})->purpose('Mark a deployment complete after readiness/launch gates pass.');

Artisan::command('vsn:deploy-fail {deployment} {reason}', /** Inline callback for this operation. */ function (string $deployment,string $reason,\App\Domain\Operations\Services\DeploymentService $service): int {
    $run=\App\Models\DeploymentRun::query()->where('public_id',$deployment)->firstOrFail();$service->fail($run,$reason);$this->error("Deployment {$deployment} failed: {$reason}");return 1;
})->purpose('Record terminal deployment failure evidence without deleting release history.');

Artisan::command('vsn:deploy-rollback-record {deployment} {target_release}', /** Inline callback for this operation. */ function (string $deployment,string $target_release,\App\Domain\Operations\Services\DeploymentService $service): void {
    $run=\App\Models\DeploymentRun::query()->where('public_id',$deployment)->firstOrFail();$service->rolledBack($run,$target_release,['source'=>'operator_cli']);$this->warn("Rollback evidence recorded for {$deployment} -> {$target_release}.");
})->purpose('Record application rollback evidence; database migration rollback remains an explicit operator decision.');

Artisan::command('vsn:incident-open {severity} {type} {title} {--summary=}', /** Inline callback for this operation. */ function (string $severity,string $type,string $title,\App\Domain\Operations\Services\IncidentManagementService $service): int {
    if(!in_array($severity,['sev1','sev2','sev3','sev4'],true))throw new \InvalidArgumentException('Severity must be sev1, sev2, sev3 or sev4.');
    $incident=$service->open(null,$severity,$type,$title,$this->option('summary')?:null,['source'=>'operator_cli']);$this->line((string)$incident->public_id);return 0;
})->purpose('Open a production incident with an append-only timeline.');

Artisan::command('vsn:incident-note {incident} {message}', /** Inline callback for this operation. */ function (string $incident,string $message,\App\Domain\Operations\Services\IncidentManagementService $service): void {
    $row=\App\Models\IncidentRecord::query()->where('public_id',$incident)->firstOrFail();$service->note($row,null,$message,['source'=>'operator_cli']);$this->info('Incident note recorded.');
})->purpose('Append an immutable operator note to an active incident.');

Artisan::command('vsn:incident-status {incident} {status} {message}', /** Inline callback for this operation. */ function (string $incident,string $status,string $message,\App\Domain\Operations\Services\IncidentManagementService $service): void {
    $row=\App\Models\IncidentRecord::query()->where('public_id',$incident)->firstOrFail();$service->status($row,null,$status,$message);$this->info("Incident {$incident}: {$status}.");
})->purpose('Move an active incident between open, investigating and monitoring states.');

Artisan::command('vsn:incident-resolve {incident} {summary}', /** Inline callback for this operation. */ function (string $incident,string $summary,\App\Domain\Operations\Services\IncidentManagementService $service): void {
    $row=\App\Models\IncidentRecord::query()->where('public_id',$incident)->firstOrFail();$service->resolve($row,null,$summary,['source'=>'operator_cli']);$this->info("Incident {$incident} resolved.");
})->purpose('Resolve an incident while retaining its append-only timeline.');

Artisan::command('vsn:ops-status', /** Inline callback for this operation. */ function (\App\Domain\Operations\Services\OperationalHealthService $health,\App\Domain\Operations\Services\ProductionConfigurationAuditService $configuration): int {
    $data=['health'=>$health->snapshot(true),'configuration'=>$configuration->audit(),'release'=>config('vsn.operations.release')];$this->line(json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));return ($data['health']['status']==='ready'&&$data['configuration']['ok'])?0:1;
})->purpose('Print a machine-readable production operations snapshot for monitors and release scripts.');

Artisan::command('vsn:backup-create', /** Inline callback for this operation. */ function (DatabaseBackupService $backups): void {
    $run=$backups->create();$this->info("Backup {$run->public_id} completed: {$run->size_bytes} bytes; SHA-256 {$run->sha256}");
})->purpose('Create a private database backup using the active MySQL/MariaDB/PostgreSQL driver.');
Artisan::command('vsn:backup-verify {backup}', /** Inline callback for this operation. */ function (string $backup, DatabaseBackupService $backups): void { $run=\App\Models\BackupRun::query()->where('public_id',$backup)->firstOrFail(); $run=$backups->verify($run); $this->info("Backup {$run->public_id} verified at {$run->verified_at}."); })->purpose('Re-read a private backup artifact and verify its stored SHA-256 checksum.');
Artisan::command('vsn:backup-prune', /** Inline callback for this operation. */ function (DatabaseBackupService $backups): void {$this->info('Expired backup artifacts removed: '.$backups->prune());})->purpose('Prune expired private application backup artifacts.');
if (config('vsn.operations.backups.enabled')) {
    Schedule::job(new CreateDatabaseBackupJob)->dailyAt('02:30');
    Schedule::command('vsn:backup-prune')->dailyAt('04:10')->withoutOverlapping();
}

Schedule::command('queue:monitor redis:critical,redis:maintenance,redis:default,redis:notifications,redis:reports --max='.(int)config('vsn.operations.queue_busy_max',500))->everyMinute()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=168')->dailyAt('04:30')->withoutOverlapping();

Artisan::command('vsn:db-index-audit', /** Inline callback for this operation. */ function (DatabaseIndexAuditService $audit): void { $r=$audit->execute(); $this->line(json_encode($r,JSON_PRETTY_PRINT)); if(($r['supported']??false)&&!($r['ok']??false))$this->error('Required production indexes are missing.'); })->purpose('Audit critical marketplace composite indexes used by hot operational queries.');


Artisan::command('vsn:launch-gate {--no-persist}', /** Inline callback for this operation. */ function (LaunchGateService $gate): int {
    $report = $gate->evaluate(null, ! (bool) $this->option('no-persist'));
    $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if (! $report['ready']) $this->error("Launch blocked by {$report['blockersCount']} blocking gate(s).");
    elseif ($report['warningsCount'] > 0) $this->warn("Launch gates passed with {$report['warningsCount']} warning(s).");
    else $this->info('All automated launch gates passed.');
    return $report['ready'] ? 0 : 1;
})->purpose('Evaluate production launch blockers, runtime verification, providers, backups and operational readiness.');

Artisan::command('vsn:providers-probe', /** Inline callback for this operation. */ function (\App\Domain\Providers\Services\ProviderRuntimeService $runtime): void {
    $rows=$runtime->probeAll();
    foreach($rows as $row)$this->line(sprintf('%-14s %-18s %-10s %s',$row['type'],$row['code'],$row['status'],$row['message']));
})->purpose('Probe configured live payment, vault, courier, SMS, email and KYC providers and persist sanitized readiness snapshots.');

Artisan::command('vsn:providers-reconcile {type?} {code?}', /** Inline callback for this operation. */ function (?string $type=null,?string $code=null): void {
    $service=app(\App\Domain\Providers\Services\ProviderReconciliationService::class);$targets=[];
    if($type&&$code)$targets=[[$type,$code]];
    else {
        if((bool)config('vsn.payments.methods.card.enabled'))$targets[]=['payment',(string)config('vsn.payments.methods.card.provider')];
        foreach(collect(config('vsn.shipping_methods',[]))->where('enabled',true)->pluck('provider')->filter()->unique() as $p)$targets[]=['shipping',(string)$p];
        if((string)config('vsn.kyc.provider','manual')!=='manual')$targets[]=['kyc',(string)config('vsn.kyc.provider')];
    }
    foreach($targets as [$t,$c]){try{$run=$service->run($t,$c,(int)config('vsn.providers.reconciliation_limit',200));$this->line("{$t}/{$c}: {$run->status}; checked {$run->checked_count}; mismatches {$run->mismatch_count}; errors {$run->error_count}");}catch(\Throwable $e){$this->error("{$t}/{$c}: {$e->getMessage()}");}}
})->purpose('Compare local payment/shipping/KYC state with configured provider state without silently overwriting financial records.');

Schedule::command('vsn:providers-probe')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('vsn:providers-reconcile')->hourly()->withoutOverlapping();

Artisan::command('vsn:kyc-provider-submit', /** Inline callback for this operation. */ function (\App\Domain\Kyc\Actions\SubmitKycVerification $submit): void {
    $count=0;\App\Models\KycVerification::query()->where('status','pending')->where('provider',(string)config('vsn.kyc.provider'))->whereNull('provider_reference')->orderBy('id')->limit(100)->get()->each(/** Inline callback for this operation. */ function($v)use($submit,&$count){$submit->submitToProvider($v);$count++;});$this->info("Pending KYC provider submissions retried: {$count}");
})->purpose('Retry KYC submissions that were stored privately but could not reach the external provider.');
Schedule::command('vsn:kyc-provider-submit')->everyFiveMinutes()->withoutOverlapping();

// Final production acceptance and disaster-recovery evidence.
Artisan::command('vsn:acceptance {--no-persist}', /** Inline callback for this operation. */ function (\App\Domain\Operations\Services\ProductionAcceptanceService $service): int {
    $report=$service->evaluate(null,! (bool)$this->option('no-persist'));
    $this->line(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    if($report['blockersCount']>0){$this->error("Acceptance blocked by {$report['blockersCount']} check(s).");return 1;}
    $this->warn('Automated acceptance checks passed; four independent sign-offs are still required.');
    return 0;
})->purpose('Evaluate final production acceptance evidence above the technical launch gate.');

Artisan::command('vsn:dr-record {status : passed|failed} {rto_minutes} {rpo_minutes} {--backup=} {--sha=}', /** Inline callback for this operation. */ function (string $status,string $rto_minutes,string $rpo_minutes): int {
    if(!in_array($status,['passed','failed'],true))throw new \InvalidArgumentException('Status must be passed or failed.');
    $backup=$this->option('backup')?\App\Models\BackupRun::query()->where('public_id',(string)$this->option('backup'))->firstOrFail():null;
    $sha=$backup?->sha256?:strtolower((string)$this->option('sha'));
    if($sha!==''&&!preg_match('/^[a-f0-9]{64}$/',$sha))throw new \InvalidArgumentException('Backup SHA must be a 64-character lowercase hex SHA-256.');
    $row=\App\Models\DisasterRecoveryDrill::query()->create(['public_id'=>(string)\Illuminate\Support\Str::ulid(),'status'=>$status,'rto_minutes'=>max(0,(int)$rto_minutes),'rpo_minutes'=>max(0,(int)$rpo_minutes),'backup_run_id'=>$backup?->id,'backup_sha256'=>$sha?:null,'evidence'=>['source'=>'operator_cli'],'started_at'=>now()->subMinutes(max(0,(int)$rto_minutes)),'completed_at'=>now()]);
    $this->info("DR evidence {$row->public_id} recorded: {$status}; RTO {$row->rto_minutes}m; RPO {$row->rpo_minutes}m.");return $status==='passed'?0:1;
})->purpose('Record immutable disaster-recovery drill evidence after an isolated restore exercise.');


Artisan::command('vsn:rc-seal {acceptance?}', /** Inline callback for this operation. */ function (?string $acceptance, \App\Domain\Operations\Services\ProductionAcceptanceService $service): int {
    $run=$acceptance?\App\Models\ProductionAcceptanceRun::query()->where('public_id',$acceptance)->firstOrFail():\App\Models\ProductionAcceptanceRun::query()->where('status','approved')->latest('approved_at')->firstOrFail();
    $manifest=$service->seal($run,null);
    $this->line(json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    $this->info('Release candidate sealed. Re-run vsn:go-live-gate for the final decision.');return 0;
})->purpose('Seal an immutable final release-candidate manifest for a fresh fully approved acceptance run.');

Artisan::command('vsn:go-live-gate', /** Inline callback for this operation. */ function (\App\Domain\Operations\Services\ProductionAcceptanceService $service): int {
    $status=$service->goLiveStatus();$this->line(json_encode($status,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    if(!$status['ready']){$this->error('GO-LIVE BLOCKED: current evidence or fresh approvals are incomplete.');return 1;}
    $this->info('GO-LIVE READY: automated evidence, four sign-offs and the immutable release-candidate seal all match the current artifact.');return 0;
})->purpose('Final go-live decision gate: re-check current evidence plus a fresh fully signed acceptance snapshot.');

// Milestone AZ — go-live and post-launch stabilization.
Artisan::command('vsn:go-live-open', /** Inline callback for this operation. */ function (\App\Domain\Operations\Services\GoLiveStabilizationService $service): int {
    $window=$service->open(null);
    $this->info($window->public_id);
    $this->line(json_encode($service->status($window),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    return 0;
})->purpose('Open a monitored go-live stabilization window only after the exact sealed release candidate is READY.');

Artisan::command('vsn:go-live-observe {window?} {--active}', /** Inline callback for this operation. */ function (?string $window, \App\Domain\Operations\Services\GoLiveStabilizationService $service): int {
    $row=null;
    if($window)$row=\App\Models\GoLiveWindow::query()->where('public_id',$window)->firstOrFail();
    elseif($this->option('active'))$row=\App\Models\GoLiveWindow::query()->where('active_environment',app()->environment())->latest('id')->first();
    else $row=\App\Models\GoLiveWindow::query()->where('active_environment',app()->environment())->latest('id')->first();
    if(!$row){$this->line('No active go-live stabilization window.');return 0;}
    $observation=$service->observe($row,true);
    $this->line(json_encode(['observation'=>$service->observationRow($observation),'status'=>$service->status($row->fresh())],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    if($observation->status==='blocked'){$this->error('Go-live stabilization observation is blocked.');return 2;}
    return 0;
})->purpose('Record an immutable production stabilization observation for the active launch window.');

Artisan::command('vsn:go-live-status {window?}', /** Inline callback for this operation. */ function (?string $window, \App\Domain\Operations\Services\GoLiveStabilizationService $service): int {
    $row=$window?\App\Models\GoLiveWindow::query()->where('public_id',$window)->firstOrFail():\App\Models\GoLiveWindow::query()->latest('id')->first();
    $status=$service->status($row);$this->line(json_encode($status,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    if(!$status['exists'])return 1;
    return (($status['latestObservation']['status']??null)==='blocked')?2:0;
})->purpose('Print post-launch stabilization state, consecutive healthy evidence and sign-off readiness.');

Artisan::command('vsn:go-live-signoff {window} {area} {userEmail} {--comment=}', /** Inline callback for this operation. */ function (string $window,string $area,string $userEmail,\App\Domain\Operations\Services\GoLiveStabilizationService $service): int {
    $row=\App\Models\GoLiveWindow::query()->where('public_id',$window)->firstOrFail();$user=\App\Models\User::query()->where('email',$userEmail)->firstOrFail();
    $this->line(json_encode($service->sign($row,$user,$area,'approved',$this->option('comment')?:null),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));return 0;
})->purpose('Record an authorized immutable post-launch stabilization approval.');

Artisan::command('vsn:go-live-rollback-record {window} {targetRelease} {note}', /** Inline callback for this operation. */ function (string $window,string $targetRelease,string $note,\App\Domain\Operations\Services\GoLiveStabilizationService $service): int {
    $row=\App\Models\GoLiveWindow::query()->where('public_id',$window)->firstOrFail();$this->line(json_encode($service->rolledBack($row,null,$targetRelease,$note),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));return 0;
})->purpose('Record application rollback evidence against the active go-live window after rollback completes.');

Schedule::command('vsn:go-live-observe --active')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('vsn:admin-create {email} {--name=VSN Super Admin} {--force}', /** Inline callback for this operation. */ function (string $email): int {
    $email = strtolower(trim($email));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('A valid email address is required.');
        return self::FAILURE;
    }

    $existing = \App\Models\User::query()->where('email', $email)->first();
    if ($existing && ! $this->option('force')) {
        $this->error('A user with this email already exists. Re-run with --force only if you intentionally want to promote/reset this account.');
        return self::FAILURE;
    }

    $password = (string) $this->secret('Password (minimum 12 characters)');
    if (mb_strlen($password) < 12) {
        $this->error('Password must contain at least 12 characters.');
        return self::FAILURE;
    }
    $confirm = (string) $this->secret('Confirm password');
    if (! hash_equals($password, $confirm)) {
        $this->error('Passwords do not match.');
        return self::FAILURE;
    }

    $user = $existing ?: new \App\Models\User();
    $user->forceFill([
        'name' => trim((string) ($this->option('name') ?: 'VSN Super Admin')),
        'email' => $email,
        'password' => \Illuminate\Support\Facades\Hash::make($password),
        'role' => \App\Enums\UserRole::SuperAdmin,
        'email_verified_at' => $user->email_verified_at ?: now(),
    ])->save();
    $user->profile()->firstOrCreate([], ['timezone' => config('app.timezone', 'UTC')]);

    $this->info("Super Admin ready: {$email}");
    return self::SUCCESS;
})->purpose('Create a real Super Admin account without enabling predictable demo credentials.');
