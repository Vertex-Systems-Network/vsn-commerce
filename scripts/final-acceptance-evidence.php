<?php

declare(strict_types=1);

$root=dirname(__DIR__);chdir($root);
$options=getopt('', ['launch::','release-metadata::','static::','capabilities::','browser::','android::','output::']);
$path=static /** Inline callback for this operation. */ function(string $key,string $default)use($options,$root):string{$v=(string)($options[$key]??$default);if($v===''||str_starts_with($v,DIRECTORY_SEPARATOR)||preg_match('/^[A-Za-z]:[\\\\\/]/',$v))return $v;return $root.'/'.ltrim($v,'/\\');};
$launch=$path('launch','runtime/launch-verification.json');
$releaseMetadata=$path('release-metadata','runtime/release-metadata.json');
$static=$path('static','runtime-artifacts/static-acceptance.json');
$capabilities=$path('capabilities','runtime/runtime-capabilities.json');
$browser=$path('browser','runtime-artifacts/browser-e2e.json');
$android=$path('android','runtime-artifacts/android-api-smoke.json');
$output=$path('output','runtime/final-acceptance-verification.json');
$read=static /** Inline callback for this operation. */ function(string $file,string $label):array{if(!is_file($file))throw new RuntimeException("Missing {$label} evidence: {$file}");$j=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);if(!is_array($j))throw new RuntimeException("Invalid {$label} evidence.");return $j;};
$launchJson=$read($launch,'launch');$releaseJson=$read($releaseMetadata,'release metadata');$staticJson=$read($static,'static audit');$capabilityJson=$read($capabilities,'runtime capabilities');$browserJson=$read($browser,'browser E2E');$androidJson=$read($android,'Android smoke');
$release=(string)($releaseJson['release']??'');$artifact=strtolower((string)($releaseJson['artifactSha256']??''));$commit=strtolower((string)($releaseJson['commitSha']??''));$composerLock=strtolower((string)($releaseJson['composerLockSha256']??''));$npmLock=strtolower((string)($releaseJson['npmLockSha256']??''));
if($release===''||!preg_match('/^[a-f0-9]{64}$/',$artifact)||!preg_match('/^[a-f0-9]{64}$/',$composerLock)||!preg_match('/^[a-f0-9]{64}$/',$npmLock))throw new RuntimeException('Release metadata must contain release, artifact SHA-256 and dependency-lock SHA-256 fingerprints.');
if(!is_file('composer.lock')||!hash_equals($composerLock,hash_file('sha256','composer.lock')))throw new RuntimeException('composer.lock does not match deployed release metadata.');
if(!is_file('package-lock.json')||!hash_equals($npmLock,hash_file('sha256','package-lock.json')))throw new RuntimeException('package-lock.json does not match deployed release metadata.');
if(($capabilityJson['passed']??false)!==true)throw new RuntimeException('Runtime capability audit did not pass.');
if((string)($launchJson['release']??'')!==$release)throw new RuntimeException('Launch verification release does not match release metadata.');
foreach(['static'=>$staticJson,'browser'=>$browserJson,'android'=>$androidJson] as $label=>$e){if(($e['passed']??false)!==true)throw new RuntimeException("{$label} evidence did not pass.");$eCommit=strtolower((string)($e['commitSha']??''));if($commit!==''&&$eCommit!==''&&!hash_equals($commit,$eCommit))throw new RuntimeException("{$label} evidence commit does not match deployed commit.");}
$baseFlags=['composerLock','npmLock','dependencies','databaseMigrations','laravelTests','frontendBuild','appSmoke','authenticatedE2E','queueHeartbeat','schedulerHeartbeat','backupRestoreDrill','providerContracts'];
foreach($baseFlags as $flag)if(($launchJson[$flag]??false)!==true)throw new RuntimeException("Launch verification flag {$flag} is not true.");
$payload=[
 'schema'=>'vsn-final-acceptance-evidence-v2','generatedAt'=>gmdate('c'),'release'=>$release,'artifactSha256'=>$artifact,'composerLockSha256'=>$composerLock,'npmLockSha256'=>$npmLock,'dependencyLocksBound'=>true,'commitSha'=>$commit?:null,
 'launchVerificationSha256'=>hash_file('sha256',$launch),'runtimeCapabilityEvidenceSha256'=>hash_file('sha256',$capabilities),'staticEvidenceSha256'=>hash_file('sha256',$static),'browserEvidenceSha256'=>hash_file('sha256',$browser),'androidEvidenceSha256'=>hash_file('sha256',$android),
];
foreach($baseFlags as $flag)$payload[$flag]=true;
foreach(['mysqlMigrationAudit','databasePortabilityAudit','performanceSecurityAudit','productionOperationsAudit','authAdminAudit'] as $flag)$payload[$flag]=($staticJson[$flag]??false)===true;
$payload['runtimeCapabilityAudit']=true;$payload['dependencyLocksBound']=true;$payload['browserE2E']=true;$payload['androidApiSmoke']=true;
@mkdir(dirname($output),0750,true);file_put_contents($output,json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
echo "Final acceptance evidence: {$output}\nSHA-256: ".hash_file('sha256',$output)."\n";
