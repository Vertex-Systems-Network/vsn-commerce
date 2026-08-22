<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = [
    'bootstrap/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
];

foreach ($directories as $relative) {
    $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

    if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
        fwrite(STDERR, "Unable to create required Laravel directory: {$path}".PHP_EOL);
        exit(1);
    }

    if (! is_writable($path)) {
        fwrite(STDERR, "Required Laravel directory is not writable: {$path}".PHP_EOL);
        exit(1);
    }
}

fwrite(STDOUT, "Laravel runtime directories are present and writable.".PHP_EOL);
