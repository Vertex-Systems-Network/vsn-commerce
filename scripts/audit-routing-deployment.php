<?php

declare(strict_types=1);

/**
 * Dependency-free routing/deployment audit.
 *
 * Verifies the Apache front-controller contract, Laravel SPA/API route sources,
 * Sanctum client path, and Vite public build base that direct browser URLs need.
 */
$root = dirname(__DIR__);
$failures = [];
$checks = 0;

/** Record one routing/deployment assertion. */
$assert = static /** Inline callback that records one routing assertion. */ function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (! $condition) {
        $failures[] = $message;
    }
};

/** Read a project file as text. */
$read = static /** Inline callback that reads one project file. */ function (string $path) use ($root): string {
    $full = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    return is_file($full) ? (string) file_get_contents($full) : '';
};

$publicHtaccess = $read('public/.htaccess');
$rootHtaccess = $read('.htaccess');
$vite = $read('vite.config.js');
$webRoutes = $read('routes/web.php');
$apiRoutes = $read('routes/api.php');
$blade = $read('resources/views/app.blade.php');
$apiClient = $read('resources/js/platform/api.js');
$main = $read('resources/js/main.jsx');
$env = $read('.env.example');

$assert($publicHtaccess !== '', 'public/.htaccess must exist.');
$assert(str_contains($publicHtaccess, 'RewriteRule ^ index.php [L]'), 'public/.htaccess must route missing paths to Laravel index.php.');
$assert(str_contains($publicHtaccess, 'REQUEST_FILENAME} !-f'), 'public/.htaccess must allow real build assets to bypass Laravel.');
$assert(str_contains($publicHtaccess, 'HTTP_AUTHORIZATION'), 'public/.htaccess must preserve Authorization headers.');
$assert(str_contains($publicHtaccess, 'HTTP_X_XSRF_TOKEN'), 'public/.htaccess must preserve Sanctum XSRF headers.');

$assert($rootHtaccess !== '', 'Root .htaccess Laragon compatibility shim must exist.');
$assert(str_contains($rootHtaccess, 'public/$1'), 'Root .htaccess must internally forward misconfigured local hosts to public/.');

$assert(str_contains($vite, "base: command === 'build' ? '/build/' : '/'"), 'Vite production base must be /build/.');
$assert(str_contains($vite, "outDir: 'public/build'"), 'Vite output directory must be public/build.');
$assert(str_contains($vite, "manifest: 'manifest.json'"), 'Vite manifest must be public/build/manifest.json.');

$assert(str_contains($webRoutes, "Route::view('/{path?}', 'app')"), 'Laravel SPA catch-all route must exist.');
foreach (['api', 'sanctum', 'storage', 'up'] as $excluded) {
    $assert(str_contains($webRoutes, $excluded), "SPA route must not swallow {$excluded} routes.");
}

$assert(str_contains($apiRoutes, "Route::prefix('v1')"), 'API v1 route group must be registered.');
$assert(str_contains($apiRoutes, "Route::post('/login', [AuthController::class, 'login'])"), 'API login route must exist.');
$assert(str_contains($apiRoutes, "Route::get('/auth/me', [AuthController::class, 'me'])"), 'API session route must exist.');
$assert(str_contains($apiRoutes, "Route::get('/cart', [CartController::class, 'show'])"), 'Cart route must exist.');
$assert(str_contains($apiRoutes, "Route::get('/recommendations', [PersonalizationController::class, 'recommendations'])"), 'Recommendations route must exist.');
$assert(str_contains($apiRoutes, "Route::get('/deals', [PromotionController::class, 'deals'])"), 'Deals route must exist.');
$assert(str_contains($apiRoutes, "Route::get('/games', [GameController::class, 'index'])"), 'Games route must exist.');

$assert(str_contains($blade, "@vite('resources/js/main.jsx')"), 'Blade shell must load the Vite entrypoint.');
$assert(str_contains($main, '<BrowserRouter>'), 'React must use BrowserRouter for clean URLs.');
$assert(str_contains($apiClient, 'const prefix = "/api/v1"'), 'Frontend API prefix must be /api/v1.');
$assert(str_contains($apiClient, '/sanctum/csrf-cookie'), 'Frontend must request the Sanctum CSRF cookie.');
$assert(str_contains($env, 'VITE_VSN_API_BASE='), 'Default Vite API base must remain same-origin/configurable.');
$assert(! is_file($root.'/public/hot'), 'public/hot must not be shipped in a production/source ZIP.');

if ($failures !== []) {
    fwrite(STDERR, "Routing/deployment audit: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Routing/deployment audit: {$checks}/{$checks} PASS\n";
