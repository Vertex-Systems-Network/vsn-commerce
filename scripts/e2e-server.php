<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

/** Handles fail e2e for the e2e-server workflow. */
function failE2e(string $message, int $code = 2): never
{
    fwrite(STDERR, "[VSN E2E] {$message}\n");
    exit($code);
}

if (! is_file($root.'/vendor/autoload.php')) {
    failE2e('vendor/autoload.php is missing. Run composer install first.');
}
if (! is_file($root.'/public/build/manifest.json')) {
    failE2e('public/build/manifest.json is missing. Run npm run build first.');
}

$connection = getenv('DB_CONNECTION') ?: 'sqlite';
$database = getenv('DB_DATABASE') ?: '';
$safeName = strtolower(str_replace('\\', '/', $database));
if (! str_contains($safeName, 'e2e') && ! str_contains($safeName, 'test')) {
    failE2e("Refusing destructive E2E reset for database [{$database}]. Database name/path must contain e2e or test.");
}

if ($connection === 'sqlite') {
    $path = $database;
    if ($path === '' || $path === ':memory:') {
        failE2e('Browser E2E requires a persistent SQLite file, not :memory:.');
    }
    if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
        $path = $root.'/'.$path;
        putenv('DB_DATABASE='.$path);
        $_ENV['DB_DATABASE'] = $path;
        $_SERVER['DB_DATABASE'] = $path;
    }
    @mkdir(dirname($path), 0777, true);
    if (! is_file($path) && @touch($path) === false) {
        failE2e("Unable to create SQLite E2E database at {$path}.");
    }
}

$artisan = escapeshellarg(PHP_BINARY).' artisan';
$exit = 0;
passthru($artisan.' migrate:fresh --seed --force --no-interaction', $exit);
if ($exit !== 0) {
    failE2e('migrate:fresh --seed failed; browser server was not started.', $exit);
}

$host = parse_url(getenv('APP_URL') ?: 'http://127.0.0.1:8010', PHP_URL_HOST) ?: '127.0.0.1';
$port = (int) (parse_url(getenv('APP_URL') ?: 'http://127.0.0.1:8010', PHP_URL_PORT) ?: 8010);
$command = $artisan.' serve --host='.escapeshellarg($host).' --port='.escapeshellarg((string) $port);
fwrite(STDOUT, "[VSN E2E] Serving {$host}:{$port} using {$connection} database {$database}\n");
passthru($command, $exit);
exit($exit);
