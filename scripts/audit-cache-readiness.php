<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [];
$issues = [];
$add = static /** Inline callback for this operation. */ function (string $label, bool $ok, string $detail = '') use (&$checks, &$issues): void {
    $checks[] = [$label,$ok,$detail];
    if (! $ok) $issues[] = $label.($detail !== '' ? ': '.$detail : '');
};

$requiredFiles = [
    'config/cache.php','config/view.php','resources/views/app.blade.php','scripts/prepare-laravel-dirs.php',
    'bootstrap/cache/.gitignore','storage/framework/cache/data/.gitignore','storage/framework/sessions/.gitignore',
    'storage/framework/views/.gitignore','storage/logs/.gitignore',
];
foreach ($requiredFiles as $relative) $add('Cache/runtime file '.$relative, is_file($root.'/'.$relative));

$dirs = ['bootstrap/cache','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs'];
foreach ($dirs as $relative) {
    $path = $root.'/'.$relative;
    $add('Runtime directory '.$relative, is_dir($path) && is_writable($path));
}

$view = (string) @file_get_contents($root.'/config/view.php');
$add('View paths configured', str_contains($view, "resource_path('views')"));
$add('Compiled view path configured', str_contains($view, "storage_path('framework/views')"));
$add('Compiled view path avoids realpath false', ! preg_match('/[\'\"]compiled[\'\"].*realpath/s', $view));

$cache = (string) @file_get_contents($root.'/config/cache.php');
$add('File cache uses Laravel runtime data path', str_contains($cache, "storage_path('framework/cache/data')"));

$composer = json_decode((string) @file_get_contents($root.'/composer.json'), true);
$pre = $composer['scripts']['pre-autoload-dump'] ?? [];
$add('Composer prepares Laravel dirs before boot', in_array('@php scripts/prepare-laravel-dirs.php', (array) $pre, true));

$env = (string) @file_get_contents($root.'/.env.example');
$prod = (string) @file_get_contents($root.'/.env.production.example');
$add('Local limiter does not require Redis', (bool) preg_match('/^CACHE_LIMITER_STORE=file$/m', $env));
$add('Production limiter uses Redis', (bool) preg_match('/^CACHE_LIMITER_STORE=redis$/m', $prod));

// config:cache safety: environment access belongs in config files, not runtime app/routes.
$runtimeEnvCalls = [];
foreach (['app','routes'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') continue;
        $src = (string) file_get_contents($file->getPathname());
        if (preg_match('/\benv\s*\(/', $src)) $runtimeEnvCalls[] = str_replace($root.'/', '', $file->getPathname());
    }
}
$add('No runtime env() calls outside config', $runtimeEnvCalls === [], implode(',', $runtimeEnvCalls));

// Route action closures prevent safe route caching. Group callbacks are allowed; action verbs are not.
$routeClosureHits = [];
foreach (glob($root.'/routes/*.php') ?: [] as $file) {
    $src = (string) file_get_contents($file);
    if (preg_match_all('/Route::(?:get|post|put|patch|delete|options|any|match|fallback)\s*\([^;]*?\b(?:function\s*\(|fn\s*\()/s', $src, $m)) {
        foreach ($m[0] as $hit) $routeClosureHits[] = basename($file).':'.preg_replace('/\s+/', ' ', substr($hit,0,120));
    }
}
$add('Route actions are cacheable (no closures)', $routeClosureHits === [], implode(' | ', $routeClosureHits));

$configClosures = [];
foreach (glob($root.'/config/*.php') ?: [] as $file) {
    $src = (string) file_get_contents($file);
    if (preg_match('/\b(?:function\s*\(|fn\s*\()/s', $src)) $configClosures[] = basename($file);
}
$add('Config files contain no closure values', $configClosures === [], implode(',', $configClosures));

foreach ($checks as [$label,$ok,$detail]) echo ($ok?'[PASS] ':'[FAIL] ').$label.($detail!==''?' ('.$detail.')':'').PHP_EOL;
echo PHP_EOL.'Cache readiness audit: '.(count($checks)-count($issues)).'/'.count($checks).' PASS'.PHP_EOL;
if ($issues) { foreach ($issues as $issue) fwrite(STDERR,' - '.$issue.PHP_EOL); exit(1); }
