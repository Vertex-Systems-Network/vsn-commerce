<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [$root.'/app', $root.'/routes', $root.'/database/seeders'];
$files = [];
foreach ($paths as $path) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) if ($file->isFile() && $file->getExtension() === 'php') $files[] = $file->getPathname();
}
sort($files);

$issues = [];
$pgFiles = 0;
$rawSqlFiles = 0;
$pgTokens = [
    ' ILIKE ', "'ilike'", 'websearch_to_tsquery', 'plainto_tsquery', 'to_tsvector',
    'ts_rank(', ' USING GIN ', 'NULLS FIRST', 'NULLS LAST', 'DISTINCT ON',
    'LANGUAGE plpgsql', 'EXECUTE FUNCTION', ' IS DISTINCT FROM ',
];

foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) continue;
    $upper = strtoupper($source);
    $containsPg = false;
    foreach ($pgTokens as $token) {
        if (str_contains($upper, strtoupper($token))) { $containsPg = true; break; }
    }
    if ($containsPg) {
        $pgFiles++;
        $guarded = preg_match('/DB::getDriverName\(\)\s*={2,3}\s*[\'\"]pgsql[\'\"]/', $source)
            || preg_match('/[\'\"]pgsql[\'\"].*DB::getDriverName\(\)/s', $source);
        if (! $guarded) $issues[] = str_replace($root.'/', '', $file).': PostgreSQL-only query syntax lacks an explicit pgsql driver guard';
    }

    if (preg_match('/(?:whereRaw|orderByRaw|selectRaw|havingRaw|groupByRaw|DB::statement|DB::unprepared)\s*\(/', $source)) $rawSqlFiles++;

    // MySQL treats arithmetic over UNSIGNED values differently from PostgreSQL.
    // Inventory availability must never be calculated by subtracting unsigned
    // stock columns directly inside SQL.
    if (preg_match('/on_hand\s*-\s*reserved(?:\s*-\s*safety_stock)?/i', $source)) {
        $issues[] = str_replace($root.'/', '', $file).': unsafe unsigned inventory subtraction remains in SQL';
    }

    // Laravel 13 rejects an empty uniqueBy argument before MySQL/MariaDB upsert.
    if (preg_match('/->upsert\s*\([^;]*,\s*\[\s*\]\s*(?:,|\))/s', $source)) {
        $issues[] = str_replace($root.'/', '', $file).': Laravel 13 upsert has an empty uniqueBy argument';
    }
}

$indexAudit = file_get_contents($root.'/app/Domain/Operations/Services/DatabaseIndexAuditService.php') ?: '';
if (! str_contains($indexAudit, "['mysql', 'mariadb']") || ! str_contains($indexAudit, 'information_schema.statistics')) {
    $issues[] = 'DatabaseIndexAuditService does not support MySQL/MariaDB information_schema index discovery';
}
$backup = file_get_contents($root.'/app/Domain/Operations/Services/DatabaseBackupService.php') ?: '';
foreach (['mysqldump', 'pg_dump', "['mysql', 'mariadb', 'pgsql']"] as $needle) {
    if (! str_contains($backup, $needle)) $issues[] = 'DatabaseBackupService missing driver-aware backup support: '.$needle;
}

if ($issues !== []) {
    fwrite(STDERR, "Database portability audit failed:\n");
    foreach ($issues as $issue) fwrite(STDERR, '- '.$issue."\n");
    exit(1);
}

echo sprintf(
    "Database portability audit passed: %d PHP runtime files scanned, %d raw-SQL files reviewed, %d PostgreSQL-specific files explicitly guarded; MySQL unsigned-stock arithmetic hazards absent.\n",
    count($files), $rawSqlFiles, $pgFiles
);
