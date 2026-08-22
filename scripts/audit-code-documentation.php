<?php

declare(strict_types=1);

/** Audits PHP named classes, interfaces, traits, enums, functions, and methods for adjacent docblocks. */
$roots = ['app', 'database', 'tests', 'scripts'];
$files = [];
foreach ($roots as $root) {
    if (! is_dir($root)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) if ($file->isFile() && $file->getExtension() === 'php') $files[] = $file->getPathname();
}
foreach (['routes/api.php','routes/console.php','bootstrap/app.php'] as $file) if (is_file($file)) $files[] = $file;

$total = 0;
$missing = [];
$classPattern = '/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+[A-Za-z_][A-Za-z0-9_]*/';
$functionPattern = '/^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+&?\s*[A-Za-z_][A-Za-z0-9_]*\s*\(/';

/** Returns true when the declaration at the provided line has an immediately associated PHPDoc block. */
$hasDoc = static /** Inline callback for this operation. */ function (array $lines, int $line): bool {
    $index = $line - 1;
    while ($index >= 0 && trim($lines[$index]) === '') $index--;
    while ($index >= 0 && str_starts_with(ltrim($lines[$index]), '#[')) {
        $index--;
        while ($index >= 0 && trim($lines[$index]) === '') $index--;
    }
    if ($index < 0 || ! str_contains($lines[$index], '*/')) return false;
    while ($index >= 0) {
        if (str_contains($lines[$index], '/**')) return true;
        if (str_contains($lines[$index], '/*')) return false;
        $index--;
    }
    return false;
};

foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $index => $line) {
        if (! preg_match($classPattern, $line) && ! preg_match($functionPattern, $line)) continue;
        $total++;
        if (! $hasDoc($lines, $index)) $missing[] = $file.':'.($index + 1).' '.$line;
    }
}

foreach ($missing as $item) echo '[FAIL] '.$item.PHP_EOL;
echo 'PHP documented declarations: '.($total - count($missing)).'/'.$total.PHP_EOL;
echo 'PHP documentation failures: '.count($missing).PHP_EOL;
exit($missing === [] ? 0 : 1);
