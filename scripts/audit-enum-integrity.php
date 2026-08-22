<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$issues = [];
$checks = 0;
$literalChecks = 0;
$migrationDefaultChecks = 0;

$fail = static /** Inline callback for this operation. */ function (string $message) use (&$issues): void { $issues[] = $message; };

$extractCall = static /** Inline callback for this operation. */ function (string $src, int $open): string {
    $depth = 0; $quote = null; $escaped = false; $length = strlen($src);
    for ($i = $open; $i < $length; $i++) {
        $ch = $src[$i];
        if ($quote !== null) {
            if ($escaped) $escaped = false;
            elseif ($ch === '\\') $escaped = true;
            elseif ($ch === $quote) $quote = null;
            continue;
        }
        if ($ch === "'" || $ch === '"') { $quote = $ch; continue; }
        if ($ch === '(') $depth++;
        elseif ($ch === ')') { $depth--; if ($depth === 0) return substr($src, $open + 1, $i - $open - 1); }
    }
    return '';
};

$enumValues = [];
foreach (glob($root.'/app/Enums/*.php') ?: [] as $file) {
    $src = (string) file_get_contents($file);
    if (! preg_match('/enum\s+([A-Za-z0-9_]+)\s*:\s*string/', $src, $em)) continue;
    preg_match_all('/case\s+[A-Za-z0-9_]+\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $src, $vm);
    if ($vm[1]) $enumValues[$em[1]] = array_fill_keys($vm[1], true);
}

$modelCasts = [];
$modelTables = [];
$snake = static /** Inline callback for this operation. */ function (string $value): string {
    return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
};
$plural = static /** Inline callback for this operation. */ function (string $value): string {
    if (preg_match('/[^aeiou]y$/', $value)) return substr($value, 0, -1).'ies';
    if (preg_match('/(s|x|z|ch|sh)$/', $value)) return $value.'es';
    return $value.'s';
};
foreach (glob($root.'/app/Models/*.php') ?: [] as $file) {
    $src = (string) file_get_contents($file);
    $model = pathinfo($file, PATHINFO_FILENAME);
    if (preg_match_all('/[\'\"]([^\'\"]+)[\'\"]\s*=>\s*([A-Za-z0-9_]+)::class/', $src, $cm, PREG_SET_ORDER)) {
        foreach ($cm as $row) if (isset($enumValues[$row[2]])) $modelCasts[$model][$row[1]] = $row[2];
    }
    if (isset($modelCasts[$model])) {
        if (preg_match('/protected\s+\$table\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $src, $tm)) $modelTables[$model] = $tm[1];
        else $modelTables[$model] = $plural($snake($model));
    }
}
$tableCasts = [];
foreach ($modelTables as $model => $table) $tableCasts[$table] = $modelCasts[$model] ?? [];

$checkLiteralBody = static /** Inline callback for this operation. */ function (string $body, string $model, string $context) use (&$literalChecks, $modelCasts, $enumValues, $fail): void {
    foreach ($modelCasts[$model] ?? [] as $field => $enum) {
        if (! preg_match_all('/[\'\"]'.preg_quote($field, '/').'[\'\"]\s*=>\s*[\'\"]([^\'\"]+)[\'\"]/', $body, $matches)) continue;
        foreach ($matches[1] as $value) {
            $literalChecks++;
            if (! isset($enumValues[$enum][$value])) {
                $allowed = implode(', ', array_keys($enumValues[$enum]));
                $fail("Invalid enum literal {$model}.{$field}={$value} in {$context}; {$enum} allows [{$allowed}]");
            }
        }
    }
};

$roots = ['app', 'database', 'tests'];
foreach ($roots as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') continue;
        $src = (string) file_get_contents($file->getPathname());
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        preg_match_all('/use\s+App\\\\Models\\\\([A-Za-z0-9_]+);/', $src, $imports);
        $models = array_values(array_filter(array_unique($imports[1] ?? []), /** Inline callback for this operation. */ fn (string $m): bool => isset($modelCasts[$m])));

        // Direct Model::create/firstOrCreate/updateOrCreate/... and Model::factory()->create/make calls.
        foreach ($models as $model) {
            $patterns = [
                '/\\b'.preg_quote($model, '/').'::(?:create|firstOrCreate|updateOrCreate|firstOrNew|createOrFirst|make)\\s*\\(/',
                '/\\b'.preg_quote($model, '/').'::factory\\(\\)->(?:create|make)\\s*\\(/',
            ];
            foreach ($patterns as $pattern) {
                if (! preg_match_all($pattern, $src, $matches, PREG_OFFSET_CAPTURE)) continue;
                foreach ($matches[0] as $match) {
                    $open = strpos($src, '(', $match[1] + strlen($match[0]) - 1);
                    if ($open === false) continue;
                    $checkLiteralBody($extractCall($src, $open), $model, $relative);
                }
            }
        }

        // Infer local variables from assignments such as $settlement = VendorSettlement::where(...)->first().
        $vars = [];
        if (preg_match_all('/\$([A-Za-z0-9_]+)\s*=\s*([A-Za-z0-9_]+)::/', $src, $vm, PREG_SET_ORDER)) {
            foreach ($vm as $row) if (isset($modelCasts[$row[2]])) $vars[$row[1]] = $row[2];
        }
        // Typed method parameters also provide a reliable local model type.
        if (preg_match_all('/\b([A-Za-z0-9_]+)\s+\$([A-Za-z0-9_]+)/', $src, $tm, PREG_SET_ORDER)) {
            foreach ($tm as $row) if (isset($modelCasts[$row[1]])) $vars[$row[2]] ??= $row[1];
        }
        foreach ($vars as $var => $model) {
            $pattern = '/\$'.preg_quote($var, '/').'->(?:forceFill|update|fill)\s*\(/';
            if (preg_match_all($pattern, $src, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $open = strpos($src, '(', $match[1]);
                    if ($open === false) continue;
                    $checkLiteralBody($extractCall($src, $open), $model, $relative.' ($'.$var.')');
                }
            }
            foreach ($modelCasts[$model] ?? [] as $field => $enum) {
                $assignmentPattern = '/\$'.preg_quote($var, '/').'->'.preg_quote($field, '/').'\s*=\s*[\'\"]([^\'\"]+)[\'\"]/';
                if (preg_match_all($assignmentPattern, $src, $am)) {
                    foreach ($am[1] as $value) {
                        $literalChecks++;
                        if (! isset($enumValues[$enum][$value])) $fail("Invalid enum assignment {$model}.{$field}={$value} in {$relative}");
                    }
                }
            }
        }
    }
}

// Migration string defaults for enum-cast model fields must also use valid backing values.
foreach (glob($root.'/database/migrations/*.php') ?: [] as $file) {
    $src = (string) file_get_contents($file);
    if (! preg_match_all('/Schema::(?:create|table)\([\'\"]([^\'\"]+)[\'\"]\s*,\s*function\s*\([^)]*\)\s*[^\{]*\{/', $src, $schema, PREG_OFFSET_CAPTURE)) continue;
    foreach ($schema[0] as $index => $match) {
        $table = $schema[1][$index][0];
        if (! isset($tableCasts[$table])) continue;
        $open = strpos($src, '{', $match[1]);
        if ($open === false) continue;
        // A small balanced-brace reader, sufficient for Schema closure bodies.
        $depth = 0; $quote = null; $escaped = false; $body = ''; $length = strlen($src);
        for ($i = $open; $i < $length; $i++) {
            $ch = $src[$i];
            if ($quote !== null) {
                if ($escaped) $escaped = false;
                elseif ($ch === '\\') $escaped = true;
                elseif ($ch === $quote) $quote = null;
            } else {
                if ($ch === "'" || $ch === '"') $quote = $ch;
                elseif ($ch === '{') $depth++;
                elseif ($ch === '}') { $depth--; if ($depth === 0) { $body = substr($src, $open + 1, $i - $open - 1); break; } }
            }
        }
        foreach ($tableCasts[$table] as $field => $enum) {
            $pattern = '/\$table->\w+\([\'\"]'.preg_quote($field, '/').'[\'\"][^;]*?->default\([\'\"]([^\'\"]+)[\'\"]\)/s';
            if (! preg_match_all($pattern, $body, $dm)) continue;
            foreach ($dm[1] as $value) {
                $migrationDefaultChecks++;
                if (! isset($enumValues[$enum][$value])) $fail("Invalid migration default {$table}.{$field}={$value}; enum {$enum}");
            }
        }
    }
}

$checks += 4;
echo '[PASS] Backed string enums discovered: '.count($enumValues).PHP_EOL;
echo '[PASS] Enum-cast models discovered: '.count($modelCasts).PHP_EOL;
echo '[PASS] Literal enum assignments checked: '.$literalChecks.PHP_EOL;
echo '[PASS] Migration enum defaults checked: '.$migrationDefaultChecks.PHP_EOL;
if ($issues) {
    foreach ($issues as $issue) fwrite(STDERR, '[FAIL] '.$issue.PHP_EOL);
    echo PHP_EOL.'Enum integrity audit: FAIL ('.count($issues).' issue(s))'.PHP_EOL;
    exit(1);
}
echo PHP_EOL.'Enum integrity audit: PASS'.PHP_EOL;
