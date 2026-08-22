<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$passes = [];

$record = static /** Inline callback for this operation. */ function (bool $ok, string $message) use (&$passes, &$failures): void {
    if ($ok) $passes[] = '[PASS] '.$message;
    else $failures[] = '[FAIL] '.$message;
};

$mustNotExist = [
    '.figma',
    'wordpress/vsn-platform',
    'resources/js/imports/pasted_text',
    'docs/history/legacy-split-layout',
];
foreach ($mustNotExist as $path) {
    $record(! file_exists($root.'/'.$path), "obsolete path absent: {$path}");
}

$forbiddenTerms = [
    'Workforce Intelligence', 'VSN Builder', 'Pella CRM', 'Pella Force', 'Pella Nova',
    'Doğa ve Bal', 'Atlas Tropikal', 'Antalya Development', 'STOM Dental', 'Çamdalı',
    'WorkspaceAccessSession', 'GridStack',
];
$scanRoots = ['app','bootstrap','config','database','routes','resources/js','tests'];
foreach ($forbiddenTerms as $term) {
    $hits = [];
    foreach ($scanRoots as $dir) {
        $base = $root.'/'.$dir;
        if (! is_dir($base)) continue;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) continue;
            $text = @file_get_contents($file->getPathname());
            if ($text !== false && stripos($text, $term) !== false) {
                $hits[] = str_replace($root.'/', '', $file->getPathname());
            }
        }
    }
    $record($hits === [], "unrelated project term absent: {$term}".($hits ? ' -> '.implode(', ', array_slice($hits, 0, 5)) : ''));
}

$frontend = '';
$frontendRoot = $root.'/resources/js';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($frontendRoot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && in_array(strtolower($file->getExtension()), ['js','jsx','ts','tsx'], true)) {
        $frontend .= "\n".@file_get_contents($file->getPathname());
    }
}
foreach ([
    'active frontend wp-json calls' => '/wp-json',
    'obsolete VITE wordpress backend switch' => 'VITE_VSN_BACKEND=wordpress',
] as $label => $needle) {
    $record(stripos($frontend, $needle) === false, $label);
}

$dbSeeder = (string) @file_get_contents($root.'/database/seeders/DatabaseSeeder.php');
$record(str_contains($dbSeeder, "if (! config('vsn.demo.enabled', false))"), 'DatabaseSeeder is gated by demo mode');

$credentials = (string) @file_get_contents($root.'/LOGIN-CREDENTIALS.md');
foreach (['customer@example.test','seller@example.test','ops-admin@example.test','admin@example.test','ChangeMe12345'] as $needle) {
    $record(str_contains($credentials, $needle), "login documentation contains {$needle}");
}

foreach ($passes as $line) echo $line.PHP_EOL;
foreach ($failures as $line) fwrite(STDERR, $line.PHP_EOL);
echo PHP_EOL.'Project isolation checks: '.(count($passes)+count($failures)).' | failures: '.count($failures).PHP_EOL;
exit($failures === [] ? 0 : 1);
