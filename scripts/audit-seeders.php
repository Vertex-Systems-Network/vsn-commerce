<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$issues = [];
$checks = [];

$pass = static /** Inline callback for this operation. */ function (string $label, bool $ok, string $detail = '') use (&$issues, &$checks): void {
    $checks[] = [$label, $ok, $detail];
    if (! $ok) $issues[] = $label.($detail !== '' ? ': '.$detail : '');
};

$seederFiles = glob($root.'/database/seeders/*.php') ?: [];
$pass('Seeder files present', count($seederFiles) >= 2, 'count='.count($seederFiles));
foreach ($seederFiles as $file) {
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $out, $code);
    $pass('Seeder syntax '.basename($file), $code === 0, implode(' ', $out));
}

// Demo seeding must stay production-safe.
$demo = (string) @file_get_contents($root.'/database/seeders/DemoEnvironmentSeeder.php');
$dbSeeder = (string) @file_get_contents($root.'/database/seeders/DatabaseSeeder.php');
$vsnConfig = (string) @file_get_contents($root.'/config/vsn.php');
$pass('Demo seeder production gate', str_contains($demo, "config('vsn.demo.enabled', false)"));
$pass('DatabaseSeeder calls DemoEnvironmentSeeder', str_contains($dbSeeder, 'DemoEnvironmentSeeder::class'));
$pass('Demo config production-safe default', str_contains($vsnConfig, "env('VSN_DEMO_SEED_ENABLED', env('APP_ENV', 'production') !== 'production')"));

// Direct seeded model keys must be mass-assignable. This intentionally checks only
// top-level array keys, not nested metadata keys.
$modelFillable = [];
foreach (glob($root.'/app/Models/*.php') ?: [] as $file) {
    $src = (string) file_get_contents($file);
    if (preg_match('/protected\s+\$fillable\s*=\s*\[(.*?)\];/s', $src, $m)) {
        preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/', $m[1], $keys);
        $modelFillable[pathinfo($file, PATHINFO_FILENAME)] = array_fill_keys($keys[1], true);
    }
}

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

$topLevelArrayKeys = static /** Inline callback for this operation. */ function (string $body): array {
    $keys = []; $arr = 0; $paren = 0; $curly = 0; $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];
        if ($ch === "'" || $ch === '"') {
            $quote = $ch; $value = ''; $escaped = false; $j = $i + 1;
            for (; $j < $len; $j++) {
                $d = $body[$j];
                if ($escaped) { $value .= $d; $escaped = false; continue; }
                if ($d === '\\') { $escaped = true; continue; }
                if ($d === $quote) break;
                $value .= $d;
            }
            $k = $j + 1; while ($k < $len && ctype_space($body[$k])) $k++;
            if ($arr === 1 && $paren === 0 && $curly === 0 && substr($body, $k, 2) === '=>') $keys[$value] = true;
            $i = $j; continue;
        }
        if ($ch === '[') $arr++; elseif ($ch === ']') $arr--;
        elseif ($ch === '(') $paren++; elseif ($ch === ')') $paren--;
        elseif ($ch === '{') $curly++; elseif ($ch === '}') $curly--;
    }
    return array_keys($keys);
};

foreach ($seederFiles as $file) {
    $src = (string) file_get_contents($file);
    if (preg_match_all('/([A-Z][A-Za-z0-9_]*)::(create|firstOrCreate|updateOrCreate)\s*\(/', $src, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $idx => $whole) {
            $model = $matches[1][$idx][0];
            if (! isset($modelFillable[$model])) continue;
            $open = strpos($src, '(', $whole[1]);
            $keys = $topLevelArrayKeys($extractCall($src, $open));
            $missing = array_values(array_filter($keys, /** Inline callback for this operation. */ fn ($key) => ! isset($modelFillable[$model][$key])));
            $pass('Seeder fillable '.$model.' in '.basename($file), $missing === [], $missing ? implode(',', $missing) : '');
        }
    }
}

// Validate literal enum backing values used by direct seeded models.
$enumValues = [];
foreach (glob($root.'/app/Enums/*.php') ?: [] as $file) {
    $src = (string) file_get_contents($file);
    preg_match_all('/case\s+\w+\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $src, $m);
    if ($m[1]) $enumValues[pathinfo($file, PATHINFO_FILENAME)] = array_fill_keys($m[1], true);
}
$modelEnumCasts = [];
foreach (glob($root.'/app/Models/*.php') ?: [] as $file) {
    $src = (string) file_get_contents($file);
    if (preg_match_all('/[\'\"]([^\'\"]+)[\'\"]\s*=>\s*([A-Za-z0-9_]+)::class/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) if (isset($enumValues[$row[2]])) $modelEnumCasts[pathinfo($file, PATHINFO_FILENAME)][$row[1]] = $row[2];
    }
}
foreach ($seederFiles as $file) {
    $src = (string) file_get_contents($file);
    if (preg_match_all('/([A-Z][A-Za-z0-9_]*)::(create|firstOrCreate|updateOrCreate)\s*\(/', $src, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $idx => $whole) {
            $model = $matches[1][$idx][0];
            if (! isset($modelEnumCasts[$model])) continue;
            $open = strpos($src, '(', $whole[1]);
            $body = $extractCall($src, $open);
            foreach ($modelEnumCasts[$model] as $field => $enum) {
                if (preg_match_all('/[\'\"]'.preg_quote($field, '/').'[\'\"]\s*=>\s*[\'\"]([^\'\"]+)[\'\"]/', $body, $vm)) {
                    foreach ($vm[1] as $value) $pass('Seeder enum '.$model.'.'.$field.'='.$value, isset($enumValues[$enum][$value]), 'enum='.$enum);
                }
            }
        }
    }
}

$pass('No legacy published review status in seeders', ! preg_match('/[\'\"]status[\'\"]\s*=>\s*[\'\"]published[\'\"]/', $demo));
$pass('Seeded review uses ReviewStatus::Approved', str_contains($demo, 'ReviewStatus::Approved'));

foreach ($checks as [$label, $ok, $detail]) echo ($ok ? '[PASS] ' : '[FAIL] ').$label.($detail !== '' ? ' ('.$detail.')' : '').PHP_EOL;
echo PHP_EOL.'Seeder audit: '.(count($checks)-count($issues)).'/'.count($checks).' PASS'.PHP_EOL;
if ($issues) { foreach ($issues as $issue) fwrite(STDERR, ' - '.$issue.PHP_EOL); exit(1); }
