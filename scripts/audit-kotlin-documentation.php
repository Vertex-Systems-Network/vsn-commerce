<?php

declare(strict_types=1);

/** Audits Kotlin classes, interfaces, objects, and functions for KDoc comments. */
$root = 'android-sdk-sample';
$total = 0;
$missing = [];
if (is_dir($root)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'kt') continue;
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $index => $line) {
            if (! preg_match('/^\s*(?:(?:public|private|protected|internal|open|final|abstract|override|suspend|inline|operator|data|sealed)\s+)*(?:class|interface|object|fun)\s+[A-Za-z_][A-Za-z0-9_]*/', $line)) continue;
            $total++;
            $cursor = $index - 1;
            while ($cursor >= 0 && trim($lines[$cursor]) === '') $cursor--;
            $documented = false;
            if ($cursor >= 0 && str_contains($lines[$cursor], '*/')) {
                while ($cursor >= 0) {
                    if (str_contains($lines[$cursor], '/**')) { $documented = true; break; }
                    if (str_contains($lines[$cursor], '/*')) break;
                    $cursor--;
                }
            }
            if (! $documented) $missing[] = $file->getPathname().':'.($index + 1).' '.$line;
        }
    }
}
foreach ($missing as $item) echo '[FAIL] '.$item.PHP_EOL;
echo 'Kotlin documented declarations: '.($total - count($missing)).'/'.$total.PHP_EOL;
echo 'Kotlin documentation failures: '.count($missing).PHP_EOL;
exit($missing === [] ? 0 : 1);
