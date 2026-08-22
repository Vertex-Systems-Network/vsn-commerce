<?php

declare(strict_types=1);

$root = dirname(__DIR__); chdir($root);
$options = getopt('', ['static-only','skip-frontend','db::','json::']);
$staticOnly = array_key_exists('static-only',$options);
$skipFrontend = array_key_exists('skip-frontend',$options);
$db = is_string($options['db'] ?? null) && $options['db'] !== '' ? $options['db'] : 'vsn_ecommerce_zero_test';
$jsonPath = is_string($options['json'] ?? null) && $options['json'] !== '' ? $options['json'] : 'runtime-artifacts/zero-to-end.json';
if (! str_contains(strtolower($db),'test')) { fwrite(STDERR,"Refusing destructive verification database [$db]; name must contain test.\n"); exit(2); }

$steps=[];$failed=false;$blocked=false;
$has = static /** Inline callback for this operation. */ function(string $cmd):bool { $probe=PHP_OS_FAMILY==='Windows'?'where '.escapeshellarg($cmd).' 2>NUL':'command -v '.escapeshellarg($cmd).' 2>/dev/null'; return trim((string)shell_exec($probe))!==''; };
$run = static /** Inline callback for this operation. */ function(string $name,string $command,bool $required=true) use (&$steps,&$failed):int {
    echo "\n=== $name ===\n$command\n"; $start=microtime(true); passthru($command,$code);
    $steps[]=['name'=>$name,'command'=>$command,'exitCode'=>$code,'seconds'=>round(microtime(true)-$start,2),'required'=>$required];
    if($required&&$code!==0)$failed=true; return $code;
};
$block = static /** Inline callback for this operation. */ function(string $message) use (&$steps,&$failed,&$blocked):void { echo "[BLOCKED] $message\n"; $steps[]=['name'=>$message,'exitCode'=>2,'required'=>true,'status'=>'blocked'];$failed=true;$blocked=true; };
$php=escapeshellarg(PHP_BINARY);

$run('Prepare Laravel runtime directories', "$php scripts/prepare-laravel-dirs.php");
$run('PHP source syntax', "$php scripts/audit-php-syntax.php");
foreach ([
    'Seeder audit'=>'scripts/audit-seeders.php',
    'Enum integrity audit'=>'scripts/audit-enum-integrity.php',
    'Cache readiness audit'=>'scripts/audit-cache-readiness.php',
    'Project isolation audit'=>'scripts/audit-project-isolation.php',
    'Marketplace media/storefront audit'=>'scripts/audit-marketplace-media-storefront.php',
    'PHP code documentation audit'=>'scripts/audit-code-documentation.php',
    'PHP anonymous documentation audit'=>'scripts/audit-anonymous-documentation.php',
    'Kotlin documentation audit'=>'scripts/audit-kotlin-documentation.php',
    'Project filename audit'=>'scripts/audit-file-names.php',
    'MySQL migrations audit'=>'scripts/audit-mysql-migrations.php',
    'Database portability audit'=>'scripts/audit-database-portability.php',
    'Performance/security audit'=>'scripts/audit-performance-security.php',
    'Auth/Admin audit'=>'scripts/audit-auth-admin-ui.php',
    'Automated test contract audit'=>'scripts/audit-test-suite.php',
] as $name=>$script) $run($name,"$php $script");
$run('Dependency-free unit smoke', "$php scripts/pure-unit-smoke.php");
$run('JavaScript code documentation audit', 'node scripts/audit-code-documentation.cjs');
$run('Frontend npm test before dependency install', 'npm test');

if (!$staticOnly) {
    foreach (['mbstring','pdo_mysql','openssl','fileinfo'] as $ext) if(!extension_loaded($ext)) $block("PHP extension $ext is required");
    if(!$has('composer')) $block('Composer v2 is required');
    if(!$has('npm')) $block('npm is required');

    if(!$blocked) {
        // Safe, dedicated database and isolated runtime drivers. Existing process environment wins over .env.
        $env=[
            'APP_ENV'=>'testing','APP_KEY'=>'base64:Z2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2c=',
            'DB_CONNECTION'=>'mysql','DB_DATABASE'=>$db,
            'DB_HOST'=>getenv('ZERO_DB_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1'),
            'DB_PORT'=>getenv('ZERO_DB_PORT') ?: (getenv('DB_PORT') ?: '3306'),
            'DB_USERNAME'=>getenv('ZERO_DB_USERNAME') ?: (getenv('DB_USERNAME') ?: 'root'),
            'DB_PASSWORD'=>getenv('ZERO_DB_PASSWORD') !== false ? (string)getenv('ZERO_DB_PASSWORD') : (getenv('DB_PASSWORD') ?: ''),
            'CACHE_STORE'=>'file','CACHE_LIMITER_STORE'=>'file','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array',
            'VSN_DEMO_SEED_ENABLED'=>'true',
        ];
        foreach($env as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}

        $run('Composer metadata', 'composer validate --no-check-publish');
        $run('Composer locked/resolved install', 'composer install --no-interaction --prefer-dist --no-progress');
        if(!is_file('composer.lock')) { $block('composer install did not create composer.lock'); }
        else $run('Composer strict lock validation', 'composer validate --strict --no-check-publish');

        if(!$blocked) {
            $run('MySQL server capability + create isolated DB', "$php scripts/mysql-runtime-preflight.php --database=".escapeshellarg($db).' --create-database');
            $run('Laravel cache reset before DB verification', "$php artisan optimize:clear");
            $run('Migration fresh + complete seeds', "$php artisan migrate:fresh --seed --force --no-interaction");
            $run('Seeder idempotency second pass', "$php artisan db:seed --force --no-interaction");
            $run('Migration convergence/no pending schema errors', "$php artisan migrate --force --no-interaction");
            $run('Laravel route boot/list', "$php artisan route:list --json");
            $run('PHPUnit Unit suite', "$php artisan test --testsuite=Unit");
            $run('Full MySQL Unit + Feature suite', "$php artisan test --configuration=phpunit.mysql.xml");

            $run('Application cache clear', "$php artisan cache:clear");
            $run('Configuration clear', "$php artisan config:clear");
            $run('Route clear', "$php artisan route:clear");
            $run('View clear', "$php artisan view:clear");
            $run('Event clear', "$php artisan event:clear");
            $run('Configuration cache', "$php artisan config:cache");
            $run('Route cache', "$php artisan route:cache");
            $run('View cache', "$php artisan view:cache");
            $run('Event cache', "$php artisan event:cache");
            $run('Optimized cached application boot', "$php artisan about --only=environment,cache,drivers");
            $run('Laravel optimize', "$php artisan optimize");
            $run('Route list while optimized', "$php artisan route:list --json");
            $run('Final optimize clear (leave source environment-neutral)', "$php artisan optimize:clear");

            if(!$skipFrontend) {
                $run('Frontend clean dependency install','npm ci --no-audit --no-fund');
                $run('Frontend npm test before build','npm test');
                $run('Frontend production build','npm run build');
                $run('Frontend npm test after build','npm test');
            }
        }
    }
}

$dir=dirname($jsonPath); if($dir!=='.'&&!is_dir($dir))@mkdir($dir,0775,true);
file_put_contents($jsonPath,json_encode(['generatedAt'=>gmdate(DATE_ATOM),'status'=>$failed?($blocked?'blocked':'failed'):'passed','staticOnly'=>$staticOnly,'database'=>$staticOnly?null:$db,'steps'=>$steps],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
echo "\nZero-to-end report: $jsonPath\n";
echo $failed ? ($blocked?"RESULT: BLOCKED\n":"RESULT: FAILED\n") : "RESULT: PASS\n";
exit($failed?1:0);
