<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$checks = [];

$read = static /** Inline callback for this operation. */ fn (string $path): string => is_file($root.'/'.$path) ? (string) file_get_contents($root.'/'.$path) : '';
$require = static /** Inline callback for this operation. */ function (bool $ok, string $label, string $detail = '') use (&$errors, &$checks): void {
    $checks[] = ['label'=>$label,'ok'=>$ok,'detail'=>$detail];
    if (! $ok) $errors[] = $label.($detail !== '' ? ': '.$detail : '');
};

$bootstrap = $read('bootstrap/app.php');
$provider = $read('app/Providers/AppServiceProvider.php');
$apiHeaders = $read('app/Http/Middleware/ApiSecurityHeaders.php');
$webHeaders = $read('app/Http/Middleware/WebSecurityHeaders.php');
$routes = $read('routes/api.php');
$appJs = $read('resources/js/App.jsx');
$prodEnv = $read('.env.production.example');
$cors = $read('config/cors.php');
$indexAudit = $read('app/Domain/Operations/Services/DatabaseIndexAuditService.php');

foreach (['LimitRequestBody','RequestPerformanceTelemetry','ApiSecurityHeaders'] as $middleware) {
    $require(str_contains($bootstrap, $middleware.'::class'), 'API middleware '.$middleware);
}
$require(str_contains($bootstrap, 'WebSecurityHeaders::class'), 'Web security headers middleware');
$require(str_contains($webHeaders, 'Content-Security-Policy'), 'Production CSP header');
$require(str_contains($webHeaders, 'Strict-Transport-Security'), 'HSTS header');
$require(str_contains($apiHeaders, "no-store, private"), 'Sensitive API no-store policy');
$require(str_contains($apiHeaders, 'stale-while-revalidate'), 'Public API cache policy');
$require(str_contains($provider, 'RequestPerformanceMetrics'), 'Query telemetry metrics');
$require(str_contains($provider, 'handleLazyLoadingViolationUsing'), 'Production N+1 lazy-loading telemetry');

foreach (['catalog-read','commerce-write','upload','provider-webhook','sensitive'] as $limiter) {
    $require(str_contains($provider, "RateLimiter::for('{$limiter}'"), 'Rate limiter '.$limiter);
    $require(str_contains($routes, "throttle:{$limiter}"), 'Route uses limiter '.$limiter);
}

$require(! str_contains($cors, "'allowed_origins' => ['*']"), 'CORS does not allow wildcard credential origin');
$require(str_contains($prodEnv, 'APP_DEBUG=false'), 'Production debug disabled');
$require(str_contains($prodEnv, 'SESSION_ENCRYPT=true'), 'Production session encryption enabled');
$require(str_contains($prodEnv, 'SESSION_SECURE_COOKIE=true'), 'Production secure session cookie enabled');
$require(str_contains($prodEnv, 'CACHE_STORE=redis'), 'Production Redis cache');
$require(str_contains($prodEnv, 'CACHE_LIMITER_STORE=redis'), 'Production Redis rate limiter');
$require(str_contains($prodEnv, 'QUEUE_CONNECTION=redis'), 'Production Redis queue');
$require(str_contains($prodEnv, 'VSN_CSP_ENABLED=true'), 'Production CSP enabled');

$uploadInspector = $read('app/Domain/Security/Services/SecureUploadInspector.php');
$require(str_contains($uploadInspector, 'max_image_pixels'), 'Image pixel/decompression budget');
$uploadFiles = [
    'ProductMediaService.php'=>'app/Domain/Catalog/Services/ProductMediaService.php',
    'KycController.php'=>'app/Http/Controllers/Api/V1/KycController.php',
    'ReviewController.php'=>'app/Http/Controllers/Api/V1/ReviewController.php',
    'MessageController.php'=>'app/Http/Controllers/Api/V1/MessageController.php',
];
foreach ($uploadFiles as $name=>$path) {
    $content=$read($path);
    $require(str_contains($content, 'SecureUploadInspector') || str_contains($content, '$this->uploads->inspect'), 'Secure upload inspection '.$name);
}


$lazyCount = preg_match_all('/\b(?:lazy|lazyNamed)\s*\(\s*(?:\/\*\*[\s\S]*?\*\/\s*)?\(\)\s*=>\s*import\s*\(/', $appJs);
$require($lazyCount >= 40, 'Route-level lazy frontend chunks', 'count='.$lazyCount);
$require(! str_contains($read('vite.config.js'), 'sourcemap: true'), 'Production source maps not forced on');

$requiredIndexes = [
    'av_products_status_recent_idx','av_products_price_idx','av_products_rating_idx','av_products_popular_idx',
    'av_categories_active_sort_idx','av_variants_product_active_idx','av_orders_user_status_idx','av_reviews_moderation_idx',
];
foreach ($requiredIndexes as $index) $require(str_contains($indexAudit, $index), 'Operations index audit '.$index);

$runtimeSources = '';
foreach (['app','routes'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) if ($file->isFile() && $file->getExtension() === 'php') $runtimeSources .= "\n".file_get_contents($file->getPathname());
}
$require(! preg_match('/\b(dd|dump|var_dump)\s*\(/', $runtimeSources), 'No debug dump calls in runtime PHP');

foreach ($checks as $check) echo ($check['ok'] ? '[PASS] ' : '[FAIL] ').$check['label'].($check['detail'] !== '' ? ' ('.$check['detail'].')' : '').PHP_EOL;
echo PHP_EOL.'Checks: '.count($checks).' | Failures: '.count($errors).PHP_EOL;
if ($errors) {
    foreach ($errors as $error) fwrite(STDERR, ' - '.$error.PHP_EOL);
    exit(1);
}
