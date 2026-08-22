<?php

declare(strict_types=1);

/** Audits project filenames for PSR-4 type matching and obvious temporary/copy naming residue. */
$errors = [];
$typeCount = 0;
$roots = ['app', 'database/factories', 'database/seeders', 'tests'];
foreach ($roots as $root) {
    if (! is_dir($root)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') continue;
        $source = file_get_contents($file->getPathname()) ?: '';
        if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $match)) {
            $typeCount++;
            if ($file->getBasename('.php') !== $match[1]) $errors[] = $file->getPathname().' must be named '.$match[1].'.php';
        }
    }
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file->isFile()) continue;
    $relative = str_replace('\\', '/', ltrim($file->getPathname(), './'));
    if (preg_match('/(?:\s|\(\d+\)|\bcopy\b|final[-_ ]final|\.bak$|\.old$|\.tmp$)/i', $file->getFilename())) $errors[] = 'Improper/temporary filename: '.$relative;
}

foreach ($errors as $error) echo '[FAIL] '.$error.PHP_EOL;
echo 'Named PHP types checked: '.$typeCount.PHP_EOL;
echo 'Filename failures: '.count($errors).PHP_EOL;
exit($errors === [] ? 0 : 1);
