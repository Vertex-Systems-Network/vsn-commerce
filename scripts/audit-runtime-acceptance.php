<?php

declare(strict_types=1);
$root=dirname(__DIR__);chdir($root);$strict=in_array('--strict',$argv,true);$checks=[];
$check=/** Inline callback for this operation. */ function(string $name,bool $ok,string $kind='contract')use(&$checks){$checks[]=['name'=>$name,'ok'=>$ok,'kind'=>$kind];echo ($ok?'[PASS] ':'[BLOCK] ').$name.PHP_EOL;};
$files=['scripts/runtime-capability-audit.php','scripts/runtime-acceptance.sh','scripts/runtime-acceptance.ps1','scripts/resolve-composer-lock.sh','scripts/resolve-composer-lock.ps1','.github/workflows/release-candidate.yml','scripts/final-acceptance-evidence.php','scripts/release-production.sh'];foreach($files as $f)$check('file '.$f,is_file($f));
$deploy=(string)file_get_contents('scripts/release-production.sh');$evidence=(string)file_get_contents('scripts/final-acceptance-evidence.php');$service=(string)file_get_contents('app/Domain/Operations/Services/ProductionAcceptanceService.php');$workflow=(string)file_get_contents('.github/workflows/release-candidate.yml');
$check('deployment records composer lock SHA',str_contains($deploy,'COMPOSER_LOCK_SHA'));
$check('deployment records npm lock SHA',str_contains($deploy,'NPM_LOCK_SHA'));
$check('release metadata contains composerLockSha256',str_contains($deploy,'composerLockSha256'));
$check('release metadata contains npmLockSha256',str_contains($deploy,'npmLockSha256'));
$check('final evidence verifies composer lock hash',str_contains($evidence,'composer.lock does not match deployed release metadata'));
$check('final evidence verifies npm lock hash',str_contains($evidence,'package-lock.json does not match deployed release metadata'));
$check('acceptance freezes dependency locks',str_contains($service,'dependency_locks')&&str_contains($service,'composer_lock_sha256')&&str_contains($service,'npm_lock_sha256'));
$check('go-live seal compares dependency locks',str_contains($service,'$seal->composer_lock_sha256')&&str_contains($service,'$seal->npm_lock_sha256'));
$check('manual RC workflow requires composer.lock',str_contains($workflow,'composer.lock is required for a release candidate'));
$check('manual RC workflow runs strict capability gate',str_contains($workflow,'runtime-capability-audit.php --strict'));
$check('manual RC evidence contains lock fingerprints',str_contains($workflow,'composerLockSha256')&&str_contains($workflow,'npmLockSha256'));
$composerLock=is_file('composer.lock');$check('current source composer.lock present',$composerLock,'runtime-blocker');
$composer=shell_exec((PHP_OS_FAMILY==='Windows'?'where composer':'command -v composer').' 2>'.(PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null'));$check('current runtime Composer available',trim((string)$composer)!=='','runtime-blocker');
$db=array_filter(['pdo_mysql','pdo_pgsql','pdo_sqlite'],/** Inline callback for this operation. */ fn($e)=>extension_loaded($e));$check('current runtime PDO database driver available',$db!==[],'runtime-blocker');
$contracts=array_filter($checks,/** Inline callback for this operation. */ fn($x)=>$x['kind']==='contract'&&!$x['ok']);$blockers=array_filter($checks,/** Inline callback for this operation. */ fn($x)=>$x['kind']==='runtime-blocker'&&!$x['ok']);echo PHP_EOL.'Contract failures: '.count($contracts).' | Runtime blockers: '.count($blockers).PHP_EOL;if($contracts)exit(1);if($strict&&$blockers)exit(2);
