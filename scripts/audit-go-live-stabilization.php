<?php

declare(strict_types=1);

$root=dirname(__DIR__);$fail=[];$checks=[];
$read=static /** Inline callback for this operation. */ fn(string $p):string=>is_file($root.'/'.$p)?(string)file_get_contents($root.'/'.$p):'';
$check=static /** Inline callback for this operation. */ function(bool $ok,string $name,string $detail='')use(&$fail,&$checks):void{$checks[]=[$ok,$name,$detail];if(!$ok)$fail[]=$name.($detail?': '.$detail:'');};

$files=[
 'database/migrations/2026_08_12_230000_add_go_live_stabilization.php',
 'app/Models/GoLiveWindow.php','app/Models/GoLiveObservation.php','app/Models/GoLiveStabilizationSignoff.php',
 'app/Domain/Operations/Services/GoLiveStabilizationService.php','app/Http/Controllers/Api/V1/AdminGoLiveController.php',
 'scripts/go-live.sh','scripts/post-launch-watch.sh','scripts/go-live-rollback.sh','scripts/go-live.ps1','scripts/post-launch-watch.ps1',
 'docs/GO-LIVE-STABILIZATION.md','tests/Feature/GoLiveStabilizationTest.php',
];
foreach($files as $f)$check(is_file($root.'/'.$f),'required file '.$f);

$m=$read('database/migrations/2026_08_12_230000_add_go_live_stabilization.php');
foreach(['go_live_windows','go_live_observations','go_live_stabilization_signoffs','active_environment','go_live_one_active_env_uq'] as $needle)$check(str_contains($m,$needle),'migration contract '.$needle);
$check(str_contains($m,'go-live evidence is append-only'),'DB-level immutable go-live evidence');

$service=$read('app/Domain/Operations/Services/GoLiveStabilizationService.php');
foreach(['goLiveStatus()','release_manifest_sha256','required_healthy_observations','paymentFailurePercent','finance_reconciliation','provider_health','critical_incidents','notification_backlog','auto_open_incident','require_distinct_signers'] as $needle)$check(str_contains($service,$needle),'stabilization service '.$needle);
$check(str_contains($service,"'status'=>'stable'")&&str_contains($service,"'status'=>'rolled_back'"),'terminal stable/rollback states');
$check(str_contains($service,'GoLiveStabilizationSignoff::query()->create'),'append-only stabilization sign-off creation');

$obs=$read('app/Models/GoLiveObservation.php');$sign=$read('app/Models/GoLiveStabilizationSignoff.php');
$check(str_contains($obs,'observations are immutable'),'observation model immutability');
$check(str_contains($sign,'sign-offs are immutable'),'sign-off model immutability');

$routes=$read('routes/api.php');
foreach(['/admin/system/go-live',"AdminGoLiveController::class, 'open'","AdminGoLiveController::class, 'observe'","AdminGoLiveController::class, 'signoff'","AdminGoLiveController::class, 'rollback'"] as $needle)$check(str_contains($routes,$needle),'go-live API '.$needle);

$console=$read('routes/console.php');
foreach(['vsn:go-live-open','vsn:go-live-observe','vsn:go-live-status','vsn:go-live-signoff','vsn:go-live-rollback-record',"Schedule::command('vsn:go-live-observe --active')->everyFiveMinutes()"] as $needle)$check(str_contains($console,$needle),'go-live console '.$needle);

$rbac=$read('config/rbac.php');$rclass=$read('app/Security/Rbac.php');
foreach(['acceptance.view','acceptance.sign'] as $needle)$check(str_contains($rbac,$needle),'Finance acceptance permission '.$needle);
$check(str_contains($rclass,"'acceptance.seal'")&&str_contains($rclass,"'acceptance.sign'")&&str_contains($rclass,"'acceptance.view'"),'split acceptance RBAC mapping');

$ui=$read('resources/js/pages/Acceptance.jsx');
foreach(['Go-live & stabilization window','Open go-live window','Record observation now','Approve stable','rollbackWindowOpen','consecutiveHealthy'] as $needle)$check(str_contains($ui,$needle),'acceptance UI '.$needle);

$productionAudit=$read('app/Domain/Operations/Services/ProductionConfigurationAuditService.php');
foreach(['go_live_signoffs','go_live_distinct_signers','go_live_stabilization_window','go_live_rollback_window','go_live_auto_incident'] as $needle)$check(str_contains($productionAudit,$needle),'production configuration '.$needle);

$config=$read('config/vsn.php');
foreach(['rollback_window_minutes','stabilization_minutes','required_healthy_observations','max_payment_failure_percent','required_signoffs'] as $needle)$check(str_contains($config,$needle),'go-live config '.$needle);

$rollback=$read('scripts/go-live-rollback.sh').$read('scripts/rollback-production.sh');
$check(!str_contains($rollback,'migrate:rollback'),'rollback scripts never blindly reverse database migrations');
$check(str_contains($read('docs/GO-LIVE-STABILIZATION.md'),'No-order activity is a warning, not a blocker'),'low-volume stabilization policy documented');

foreach($checks as [$ok,$name,$detail])echo ($ok?'[PASS] ':'[FAIL] ').$name.($detail?' ('.$detail.')':'').PHP_EOL;
echo PHP_EOL.'Checks: '.count($checks).' | Failures: '.count($fail).PHP_EOL;
if($fail){foreach($fail as $f)fwrite(STDERR,' - '.$f.PHP_EOL);exit(1);} 
