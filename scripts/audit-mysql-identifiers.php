<?php

declare(strict_types=1);

/**
 * MySQL/MariaDB migration identifier audit.
 *
 * Laravel derives unnamed index / unique / foreign-key names from the table,
 * columns and index type. MySQL limits identifiers to 64 characters. This
 * scanner parses complete Schema callback bodies (including compressed one-line
 * migrations), calculates effective generated names, and fails before migrate.
 */

$root = dirname(__DIR__);
$files = glob($root.'/database/migrations/*.php') ?: [];
sort($files);
$offenders = [];
$checked = [];

$splitArgs = static /** Inline callback for this operation. */ function (string $input): array {
    $args = [];
    $current = '';
    $depth = 0;
    $quote = null;
    $escape = false;
    $length = strlen($input);

    for ($i = 0; $i < $length; $i++) {
        $char = $input[$i];
        if ($quote !== null) {
            $current .= $char;
            if ($escape) {
                $escape = false;
            } elseif ($char === '\\') {
                $escape = true;
            } elseif ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"') {
            $quote = $char;
            $current .= $char;
        } elseif (str_contains('([{', $char)) {
            $depth++;
            $current .= $char;
        } elseif (str_contains(')]}', $char)) {
            $depth--;
            $current .= $char;
        } elseif ($char === ',' && $depth === 0) {
            $args[] = trim($current);
            $current = '';
        } else {
            $current .= $char;
        }
    }

    if (trim($current) !== '' || $args !== []) {
        $args[] = trim($current);
    }

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
    if (!preg_match_all('/Schema::(?:create|table)\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,/', $text, $matches, PREG_OFFSET_CAPTURE)) {
        return $result;
    }

    foreach ($matches[0] as $index => $fullMatch) {
        $table = $matches[1][$index][0];
        $start = $fullMatch[1] + strlen($fullMatch[0]);
        $brace = strpos($text, '{', $start);
        if ($brace === false) continue;

        $depth = 0;
        $quote = null;
        $escape = false;
        $length = strlen($text);
        for ($i = $brace; $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== null) {
                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $result[] = [$table, substr($text, $brace + 1, $i - $brace - 1)];
                    break;
                }
            }
        }
    }

    return $result;
};

$record = static /** Inline callback for this operation. */ function (string $file, string $name, string $kind) use (&$checked, &$offenders): void {
    $key = basename($file).'|'.$name.'|'.$kind;
    if (isset($checked[$key])) return;
    $checked[$key] = true;
    $length = strlen($name);
    if ($length > 64) {
        $offenders[] = [basename($file), $name, $kind, $length];
    }
};

foreach ($files as $file) {
    $text = file_get_contents($file);
    if ($text === false) continue;

    foreach ($callbacks($text) as [$table, $body]) {
        // Blueprint-level compound / single-column index methods.
        if (preg_match_all('/\$\w+->(index|unique|primary|fullText|spatialIndex)\s*\((.*?)\)/s', $body, $calls, PREG_SET_ORDER)) {
            foreach ($calls as $call) {
                $kind = $call[1];
                $args = $splitArgs($call[2]);
                if ($args === []) continue;
                $columns = $columnsArg($args[0]);
                if ($columns === []) {
                    $single = $stringArg($args[0]);
                    if ($single !== null) $columns = [$single];
                }
                if ($columns === []) continue;
                $explicit = isset($args[1]) ? $stringArg($args[1]) : null;
                $suffix = match ($kind) {
                    'fullText' => 'fulltext',
                    'spatialIndex' => 'spatialindex',
                    default => strtolower($kind),
                };
                $name = $explicit ?? ($table.'_'.implode('_', $columns).'_'.$suffix);
                $record($file, $name, $kind);
            }
        }

        // Chained column indexes / uniques and constrained foreign IDs.
        foreach (preg_split('/;/', $body) ?: [] as $statement) {
            if (!preg_match('/\$\w+->(?:[A-Za-z_][A-Za-z0-9_]*)\(\s*[\'\"]([^\'\"]+)[\'\"]/', $statement, $columnMatch)) {
                continue;
            }
            $column = $columnMatch[1];

            if (preg_match_all('/->(index|unique|primary|fullText|spatialIndex)\s*\((.*?)\)/s', $statement, $chainCalls, PREG_SET_ORDER)) {
                foreach ($chainCalls as $chainCall) {
                    $kind = $chainCall[1];
                    $args = $splitArgs($chainCall[2]);
                    $explicit = isset($args[0]) && trim($args[0]) !== '' ? $stringArg($args[0]) : null;
                    $suffix = match ($kind) {
                        'fullText' => 'fulltext',
                        'spatialIndex' => 'spatialindex',
                        default => strtolower($kind),
                    };
                    $record($file, $explicit ?? ($table.'_'.$column.'_'.$suffix), $kind);
                }
            }

            if (preg_match('/->(?:foreignId|foreignUuid|foreignUlid)\(\s*[\'\"]'.preg_quote($column, '/').'[\'\"]\s*\).*?->constrained\s*\((.*?)\)/s', $statement, $foreignMatch)) {
                $args = $splitArgs($foreignMatch[1]);
                $explicit = null;
                foreach ($args as $arg) {
                    if (preg_match('/^indexName\s*:\s*[\'\"]([^\'\"]+)[\'\"]$/', trim($arg), $named)) {
                        $explicit = $named[1];
                        break;
                    }
                }
                $record($file, $explicit ?? ($table.'_'.$column.'_foreign'), 'foreign');
            }

            if (preg_match('/->(?:morphs|nullableMorphs|uuidMorphs|nullableUuidMorphs|ulidMorphs|nullableUlidMorphs)\(\s*[\'\"]([^\'\"]+)[\'\"]/', $statement, $morph)) {
                $name = $morph[1];
                $record($file, $table.'_'.$name.'_type_'.$name.'_id_index', 'morph-index');
            }
        }

        // Explicit Blueprint foreign() calls.
        if (preg_match_all('/\$\w+->foreign\s*\((.*?)\)/s', $body, $foreignCalls, PREG_SET_ORDER)) {
            foreach ($foreignCalls as $foreignCall) {
                $args = $splitArgs($foreignCall[1]);
                if ($args === []) continue;
                $columns = $columnsArg($args[0]);
                if ($columns === []) {
                    $single = $stringArg($args[0]);
                    if ($single !== null) $columns = [$single];
                }
                if ($columns === []) continue;
                $explicit = isset($args[1]) ? $stringArg($args[1]) : null;
                $record($file, $explicit ?? ($table.'_'.implode('_', $columns).'_foreign'), 'foreign');
            }
        }
    }

    // Explicit raw index identifiers are also subject to MySQL's identifier limit
    // if they ever run on MySQL.
    if (preg_match_all('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+([A-Za-z0-9_]+)/i', $text, $rawIndexes)) {
        foreach ($rawIndexes[1] as $name) {
            $record($file, $name, 'raw-index');
        }
    }
}

if ($offenders !== []) {
    fwrite(STDERR, "MySQL identifier audit failed (maximum 64 characters):\n");
    foreach ($offenders as [$file, $name, $kind, $length]) {
        fwrite(STDERR, sprintf("- %d chars [%s] %s (%s)\n", $length, $kind, $name, $file));
    }
    exit(1);
}

echo sprintf("MySQL identifier audit passed: %d effective identifier definitions checked; none exceed 64 characters.\n", count($checked));
