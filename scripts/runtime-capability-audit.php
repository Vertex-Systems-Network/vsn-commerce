<?php

declare(strict_types=1);
$root=dirname(__DIR__);chdir($root);
$options=getopt('', ['strict','json::']);$strict=isset($options['strict']);
$checks=[];$add=/** Inline callback for this operation. */ function(string $name,bool $ok,string $message,array $details=[])use(&$checks){$checks[]=['name'=>$name,'ok'=>$ok,'message'=>$message,'details'=>$details];};
$cmd=/** Inline callback for this operation. */ function(string $name):?string{$out=shell_exec((PHP_OS_FAMILY==='Windows'?'where ':'command -v ').escapeshellarg($name).' 2>'.(PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null'));$v=trim((string)$out);return $v!==''?strtok($v,"\r\n"):null;};
$add('php_version',version_compare(PHP_VERSION,'8.4.0','>='),'PHP 8.4+ required.',['version'=>PHP_VERSION]);
foreach(['json','mbstring','openssl','pdo'] as $ext)$add('php_ext_'.$ext,extension_loaded($ext),"PHP extension {$ext} is required.");
$composer=$cmd('composer');$node=$cmd('node');$npm=$cmd('npm');
$add('composer_binary',$composer!==null,'Composer v2 must be installed.',['path'=>$composer]);
$add('node_binary',$node!==null,'Node.js 22 must be installed.',['path'=>$node]);
$add('npm_binary',$npm!==null,'npm must be installed.',['path'=>$npm]);
$composerLock=is_file('composer.lock');$npmLock=is_file('package-lock.json');
$add('composer_lock',$composerLock,'composer.lock must exist before runtime acceptance.',['sha256'=>$composerLock?hash_file('sha256','composer.lock'):null]);
$add('npm_lock',$npmLock,'package-lock.json must exist.',['sha256'=>$npmLock?hash_file('sha256','package-lock.json'):null]);
if($npmLock&&is_file('package.json')){$p=json_decode((string)file_get_contents('package.json'),true);$l=json_decode((string)file_get_contents('package-lock.json'),true);$aligned=is_array($p)&&is_array($l)&&($p['dependencies']??[])===($l['packages']['']['dependencies']??[])&&($p['devDependencies']??[])===($l['packages']['']['devDependencies']??[]);$add('npm_lock_alignment',$aligned,'package.json and package-lock.json root dependencies must match.');}
$writable=is_dir('bootstrap/cache')&&is_writable('bootstrap/cache')&&is_dir('storage')&&is_writable('storage');$add('laravel_writable_dirs',$writable,'storage and bootstrap/cache must be writable.');
$dbExt=array_values(array_filter(['pdo_mysql','pdo_pgsql','pdo_sqlite'],/** Inline callback for this operation. */ fn($e)=>extension_loaded($e)));$add('database_driver_extension',$dbExt!==[],'At least one supported PDO database driver must be loaded.',['loaded'=>$dbExt]);
$blockers=array_values(array_filter($checks,/** Inline callback for this operation. */ fn($c)=>!$c['ok']));$report=['schema'=>'vsn-runtime-capability-v1','generatedAt'=>gmdate('c'),'passed'=>$blockers===[],'strict'=>$strict,'checks'=>$checks,'blockersCount'=>count($blockers),'blockers'=>$blockers];
$out=(string)($options['json']??'runtime-artifacts/runtime-capabilities.json');if($out!==''){@mkdir(dirname($out),0750,true);file_put_contents($out,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);}echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($strict&&$blockers?1:0);
