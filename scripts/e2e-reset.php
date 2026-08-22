<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

$connection = getenv('DB_CONNECTION') ?: 'sqlite';
$database = getenv('DB_DATABASE') ?: '';
$safeName = strtolower(str_replace('\\', '/', $database));

if (! str_contains($safeName, 'e2e') && ! str_contains($safeName, 'test')) {
    fwrite(STDERR, "[VSN E2E] Refusing reset for unsafe database [{$database}].\n");
    exit(2);
}

if ($connection === 'sqlite' && $database && $database !== ':memory:') {
    $path = $database;
    if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) $path = $root.'/'.$path;
    @mkdir(dirname($path), 0777, true);
    if (! is_file($path)) @touch($path);
}

$cmd = escapeshellarg(PHP_BINARY).' artisan migrate:fresh --seed --force --no-interaction';
passthru($cmd, $exit);
exit($exit);
