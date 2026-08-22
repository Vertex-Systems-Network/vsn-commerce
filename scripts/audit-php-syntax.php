<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$count = 0;
$errors = [];
foreach ($it as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    $normalized = str_replace('\\', '/', $path);
    if (str_contains($normalized, '/vendor/') || str_contains($normalized, '/node_modules/')) continue;
    $count++;
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $code);
    if ($code !== 0) $errors[] = str_replace($root.'/', '', $normalized).': '.implode(' ', $output);
}
echo 'PHP syntax files: '.$count.'; errors: '.count($errors).PHP_EOL;
if ($errors) {
    foreach ($errors as $error) fwrite(STDERR, '[FAIL] '.$error.PHP_EOL);
    exit(1);
}
echo 'PHP syntax audit PASS'.PHP_EOL;
