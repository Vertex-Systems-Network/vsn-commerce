<?php

declare(strict_types=1);

/**
 * Deeper MySQL/MariaDB schema audit for VSN Ecommerce.
 *
 * This is intentionally dependency-free so Composer may execute it before
 * Laravel's autoloader exists. It validates migration dependency order,
 * MySQL index key-size estimates under utf8mb4, index-count safety, and the
 * first-class Laravel MySQL/MariaDB configuration required by Laragon.
 */

$root = dirname(__DIR__);
$files = glob($root.'/database/migrations/*.php') ?: [];
sort($files);
$issues = [];
$warnings = [];
$created = [];
$columnWidths = [];
$indexCounts = [];
$maxIndex = ['bytes' => 0, 'table' => null, 'columns' => [], 'file' => null];
$dependencyChecks = 0;

$splitArgs = static /** Inline callback for this operation. */ function (string $input): array {
    $args = [];
    $current = '';
    $depth = 0;
    $quote = null;
    $escape = false;
    for ($i = 0, $length = strlen($input); $i < $length; $i++) {
        $char = $input[$i];
        if ($quote !== null) {
            $current .= $char;
            if ($escape) $escape = false;
            elseif ($char === '\\') $escape = true;
            elseif ($char === $quote) $quote = null;
            continue;
        }
        if ($char === "'" || $char === '"') { $quote = $char; $current .= $char; }
        elseif (str_contains('([{', $char)) { $depth++; $current .= $char; }
        elseif (str_contains(')]}', $char)) { $depth--; $current .= $char; }
        elseif ($char === ',' && $depth === 0) { $args[] = trim($current); $current = ''; }
        else $current .= $char;
    }
    if (trim($current) !== '' || $args !== []) $args[] = trim($current);
    return $args;
};

$stringArg = static /** Inline callback for this operation. */ function (?string $arg): ?string {
    if ($arg === null) return null;
    return preg_match('/^[\'\"]([^\'\"]+)[\'\"]$/', trim($arg), $m) ? $m[1] : null;
};

$columnsArg = static /** Inline callback for this operation. */ function (string $arg): array {
    preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/', $arg, $matches);
    return $matches[1] ?? [];
};

$callbacks = static /** Inline callback for this operation. */ function (string $text): array {
    $result = [];
    if (! preg_match_all('/Schema::(create|table)\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,/', $text, $matches, PREG_OFFSET_CAPTURE)) return $result;
    foreach ($matches[0] as $index => $fullMatch) {
        $kind = $matches[1][$index][0];
        $table = $matches[2][$index][0];
        $offset = $fullMatch[1];
        $start = $offset + strlen($fullMatch[0]);
        $brace = strpos($text, '{', $start);
        if ($brace === false) continue;
        $depth = 0; $quote = null; $escape = false;
        for ($i = $brace, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== null) {
                if ($escape) $escape = false;
                elseif ($char === '\\') $escape = true;
                elseif ($char === $quote) $quote = null;
                continue;
            }
            if ($char === "'" || $char === '"') $quote = $char;
            elseif ($char === '{') $depth++;
            elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $result[] = [$offset, $kind, $table, substr($text, $brace + 1, $i - $brace - 1)];
                    break;
                }
            }
        }
    }
    usort($result, /** Inline callback for this operation. */ fn (array $a, array $b): int => $a[0] <=> $b[0]);
    return $result;
};

$plural = static /** Inline callback for this operation. */ function (string $word): string {
    if (preg_match('/[^aeiou]y$/i', $word)) return substr($word, 0, -1).'ies';
    if (preg_match('/(?:s|x|z|ch|sh)$/i', $word)) return $word.'es';
    return $word.'s';
};

$widthFor = static /** Inline callback for this operation. */ function (string $type, array $args): int {
    $type = strtolower($type);
    $length = isset($args[1]) && preg_match('/^\d+$/', trim($args[1])) ? (int) trim($args[1]) : null;
    return match ($type) {
        'string' => 4 * ($length ?: 255),
        'char' => 4 * ($length ?: 255),
        'uuid', 'foreignuuid' => 4 * 36,
        'ulid', 'foreignulid' => 4 * 26,
        'biginteger', 'unsignedbiginteger', 'foreignid', 'id' => 8,
        'integer', 'unsignedinteger' => 4,
        'smallinteger', 'unsignedsmallinteger' => 2,
        'tinyinteger', 'unsignedtinyinteger', 'boolean' => 1,
        'date', 'datetime', 'datetimetz', 'timestamp', 'timestamptz', 'time', 'timetz' => 8,
        'decimal', 'unsigneddecimal', 'float', 'double' => 16,
        'text', 'mediumtext', 'longtext', 'json', 'jsonb' => 65535,
        default => 16,
    };
};

$recordIndex = static /** Inline callback for this operation. */ function (string $file, string $table, array $columns, string $kind) use (&$columnWidths, &$indexCounts, &$maxIndex, &$issues): void {
    if ($kind === 'fullText' || $kind === 'spatialIndex') return;
    $bytes = 0;
    foreach ($columns as $column) $bytes += (int) ($columnWidths[$table][$column] ?? 16);
    $indexCounts[$table] = ($indexCounts[$table] ?? 0) + 1;
    if ($bytes > $maxIndex['bytes']) $maxIndex = ['bytes' => $bytes, 'table' => $table, 'columns' => $columns, 'file' => basename($file)];
    if ($bytes > 3072) {
        $issues[] = basename($file).": estimated utf8mb4 B-tree key exceeds InnoDB 3072-byte limit on {$table} (".implode(',', $columns).") = {$bytes} bytes";
    }
};

foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) { $issues[] = basename($file).': unreadable migration'; continue; }

    foreach ($callbacks($source) as [, $kind, $table, $body]) {
        if ($kind === 'table' && ! isset($created[$table])) {
            $issues[] = basename($file).": Schema::table targets {$table} before that table is created";
        }
        if ($kind === 'create') {
            if (isset($created[$table])) $issues[] = basename($file).": duplicate Schema::create for {$table}";
            $available = $created;
            $available[$table] = true; // self-referential FK (categories.parent_id) is valid.
        } else {
            $available = $created;
        }

        foreach (preg_split('/;/', $body) ?: [] as $statement) {
            if (preg_match('/\$\w+->([A-Za-z_][A-Za-z0-9_]*)\((.*?)\)/s', $statement, $columnCall)) {
                $type = $columnCall[1];
                $args = $splitArgs($columnCall[2]);
                $column = $args !== [] ? $stringArg($args[0]) : null;
                $nonColumns = ['index','unique','primary','foreign','fullText','spatialIndex','dropIndex','dropUnique','dropPrimary','dropForeign','dropFullText','dropSpatialIndex','renameIndex','dropColumn','dropConstrainedForeignId'];
                if ($column !== null && ! in_array($type, $nonColumns, true)) {
                    $columnWidths[$table][$column] = $widthFor($type, $args);
                    if (preg_match_all('/->(index|unique|primary|fullText|spatialIndex)\s*\((.*?)\)/s', $statement, $chainIndexes, PREG_SET_ORDER)) {
                        foreach ($chainIndexes as $chainIndex) $recordIndex($file, $table, [$column], $chainIndex[1]);
                    }
                }
            }

            if (preg_match('/->(?:foreignId|foreignUuid|foreignUlid)\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)(.*?)->constrained\s*\((.*?)\)/s', $statement, $fk)) {
                $column = $fk[1];
                $args = trim($fk[3]);
                $target = null;
                if (preg_match('/[\'\"]([^\'\"]+)[\'\"]/', $args, $targetMatch)) $target = $targetMatch[1];
                if ($target === null) {
                    $base = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;
                    $target = $plural($base);
                }
                $dependencyChecks++;
                if (! isset($available[$target])) $issues[] = basename($file).": FK {$table}.{$column} references {$target} before it is created";
                $indexCounts[$table] = ($indexCounts[$table] ?? 0) + 1;
            }

            if (preg_match('/->foreign\s*\((.*?)\).*?->on\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/s', $statement, $fk)) {
                $dependencyChecks++;
                if (! isset($available[$fk[2]])) $issues[] = basename($file).": explicit FK on {$table} references {$fk[2]} before it is created";
                $indexCounts[$table] = ($indexCounts[$table] ?? 0) + 1;
            }
        }

        if (preg_match_all('/\$\w+->(index|unique|primary|fullText|spatialIndex)\s*\((.*?)\)/s', $body, $indexes, PREG_SET_ORDER)) {
            foreach ($indexes as $index) {
                $args = $splitArgs($index[2]);
                if ($args === []) continue;
                $columns = $columnsArg($args[0]);
                if ($columns === []) { $one = $stringArg($args[0]); if ($one !== null) $columns = [$one]; }
                if ($columns !== []) $recordIndex($file, $table, $columns, $index[1]);
            }
        }

        if ($kind === 'create') $created[$table] = true;
    }
}

foreach ($indexCounts as $table => $count) {
    // InnoDB permits at most 64 secondary indexes. The parser is deliberately
    // conservative and may double-count chained declarations, so 56 is used as
    // an early warning and 64 as a hard blocker.
    if ($count > 64) $issues[] = "{$table}: estimated index count {$count} exceeds InnoDB limit 64";
    elseif ($count > 56) $warnings[] = "{$table}: estimated index count {$count} is close to the InnoDB limit";
}

$config = file_get_contents($root.'/config/database.php') ?: '';
$envExample = file_get_contents($root.'/.env.example') ?: '';
if (! preg_match('/[\'\"]mysql[\'\"]\s*=>\s*\[/', $config) || ! preg_match('/[\'\"]mariadb[\'\"]\s*=>\s*\[/', $config)) {
    $issues[] = 'config/database.php must define both mysql and mariadb connections';
}
if (! str_contains($config, "env('DB_CONNECTION', 'mysql')")) $issues[] = 'config/database.php default connection is not mysql';
foreach (["'charset' => env('DB_CHARSET', 'utf8mb4')", "'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci')", "'strict' => true"] as $needle) {
    if (! str_contains($config, $needle)) $issues[] = 'MySQL configuration missing required setting: '.$needle;
}
foreach (['DB_CONNECTION=mysql','DB_HOST=127.0.0.1','DB_PORT=3306','DB_CHARSET=utf8mb4','DB_COLLATION=utf8mb4_unicode_ci'] as $needle) {
    if (! str_contains($envExample, $needle)) $issues[] = '.env.example missing Laragon/MySQL default: '.$needle;
}

$guardMigration = file_get_contents($root.'/database/migrations/2026_08_08_003900_add_mysql_runtime_compatibility_guards.php') ?: '';
foreach (['carts_one_active_user_mysql_uq','checkout_one_reserved_cart_mysql_uq','saved_payment_default_user_mysql_uq','tax_jurisdiction_region_mysql_uq','tax_class_one_default_mysql_uq'] as $guard) {
    if (! str_contains($guardMigration, $guard)) $issues[] = "MySQL partial-unique compatibility guard missing: {$guard}";
}

if ($issues !== []) {
    fwrite(STDERR, "MySQL schema audit failed:\n");
    foreach ($issues as $issue) fwrite(STDERR, '- '.$issue."\n");
    exit(1);
}

foreach ($warnings as $warning) fwrite(STDERR, '[warning] '.$warning."\n");
echo sprintf(
    "MySQL schema audit passed: %d migrations, %d tables, %d FK dependency checks; largest estimated utf8mb4 B-tree key %d bytes (%s.%s); index-count limits clean.\n",
    count($files), count($created), $dependencyChecks, $maxIndex['bytes'], $maxIndex['table'] ?? '-', implode(',', $maxIndex['columns'])
);
