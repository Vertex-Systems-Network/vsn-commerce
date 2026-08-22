<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migrations = glob($root.'/database/migrations/*.php') ?: [];
sort($migrations);
$issues = [];

// First run the effective Laravel index / unique / FK identifier-name audit.
$identifierAudit = __DIR__.'/audit-mysql-identifiers.php';
if (!is_file($identifierAudit)) {
    fwrite(STDERR, "Missing MySQL identifier audit script.\n");
    exit(1);
}

$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($identifierAudit);
passthru($command, $identifierStatus);
if ($identifierStatus !== 0) {
    exit($identifierStatus);
}

foreach (['audit-mysql-schema.php', 'audit-database-portability.php'] as $extraAudit) {
    $auditPath = __DIR__.'/'.$extraAudit;
    if (! is_file($auditPath)) {
        fwrite(STDERR, "Missing database audit script: {$extraAudit}\n");
        exit(1);
    }
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($auditPath), $auditStatus);
    if ($auditStatus !== 0) exit($auditStatus);
}

$postgresOnlyTokens = [
    'LANGUAGE plpgsql',
    'RETURNS trigger',
    'EXECUTE FUNCTION',
    'tsvector',
    'USING GIN',
    'IS DISTINCT FROM',
];

foreach ($migrations as $migration) {
    $source = file_get_contents($migration);
    if ($source === false) {
        $issues[] = basename($migration).': unreadable migration';
        continue;
    }

    // PHP parser validation using the exact runtime executing Composer.
    $lintOutput = [];
    $lintStatus = 0;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($migration).' 2>&1', $lintOutput, $lintStatus);
    if ($lintStatus !== 0) {
        $issues[] = basename($migration).': PHP syntax error: '.implode(' ', $lintOutput);
    }

    // MySQL limits table/column/index/constraint identifiers to 64 characters.
    if (preg_match_all('/Schema::(?:create|table)\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $tableMatches)) {
        foreach ($tableMatches[1] as $table) {
            if (strlen($table) > 64) {
                $issues[] = basename($migration).": table identifier exceeds 64 chars: {$table}";
            }
        }
    }

    // Column declarations always use the first string argument. Skip Blueprint
    // methods whose first string argument is an index/constraint name, not a column.
    $nonColumnMethods = [
        'index','unique','primary','foreign','fullText','spatialIndex',
        'dropIndex','dropUnique','dropPrimary','dropForeign','dropFullText','dropSpatialIndex',
        'renameIndex','dropColumn','dropConstrainedForeignId',
    ];
    if (preg_match_all('/\$\w+->([A-Za-z_][A-Za-z0-9_]*)\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $columnCalls, PREG_SET_ORDER)) {
        foreach ($columnCalls as $call) {
            if (in_array($call[1], $nonColumnMethods, true)) continue;
            if (strlen($call[2]) > 64) {
                $issues[] = basename($migration).": column identifier exceeds 64 chars: {$call[2]}";
            }
        }
    }

    // MySQL partial-unique guard migrations must use indexed VIRTUAL generated
    // columns, not STORED ones. A STORED generated column that depends on a foreign-key
    // base column can make existing ON DELETE SET NULL/CASCADE constraints invalid and
    // surface as MySQL error 1215 during ALTER TABLE.
    if (str_contains($source, 'mysql_') && str_contains($source, '_guard') && str_contains($source, '->storedAs(')) {
        $issues[] = basename($migration).': MySQL generated guard uses storedAs(); use virtualAs() to preserve FK referential actions';
    }

    // PostgreSQL-only raw SQL must never execute on MySQL. Every migration that
    // contains a known PG-only construct must have an explicit pgsql driver guard.
    $containsPostgresSql = false;
    foreach ($postgresOnlyTokens as $token) {
        if (stripos($source, $token) !== false) {
            $containsPostgresSql = true;
            break;
        }
    }
    if ($containsPostgresSql && !preg_match('/DB::getDriverName\(\)\s*={2,3}\s*[\'\"]pgsql[\'\"]/', $source)) {
        $issues[] = basename($migration).': PostgreSQL-only raw SQL is not protected by a pgsql driver guard';
    }
}

if ($issues !== []) {
    fwrite(STDERR, "MySQL migration preflight failed:\n");
    foreach ($issues as $issue) {
        fwrite(STDERR, '- '.$issue."\n");
    }
    exit(1);
}

echo sprintf(
    "MySQL migration preflight passed: %d migration files linted; identifier limits and PostgreSQL SQL guards are clean.\n",
    count($migrations)
);
