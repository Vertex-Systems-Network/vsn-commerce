<?php

declare(strict_types=1);

/** Audits PHP anonymous functions, arrow functions, and anonymous classes for inline documentation. */
$roots = ['app','database','tests','scripts','routes','bootstrap','config'];
$files = [];
foreach ($roots as $root) {
    if (! is_dir($root)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) if ($file->isFile() && $file->getExtension() === 'php') $files[] = $file->getPathname();
}
$total = 0;
$missing = [];
foreach ($files as $file) {
    $source = file_get_contents($file) ?: '';
    $tokens = token_get_all($source);
    $offset = 0;
    foreach ($tokens as $index => $token) {
        $text = is_array($token) ? $token[1] : $token;
        $id = is_array($token) ? $token[0] : null;
        $start = $offset;
        $offset += strlen($text);
        $needsDoc = false;
        if ($id === T_FN) $needsDoc = true;
        if ($id === T_FUNCTION) {
            $cursor = $index + 1;
            while ($cursor < count($tokens)) {
                $next = $tokens[$cursor];
                if (is_array($next) && in_array($next[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) { $cursor++; continue; }
                if ($next === '&') { $cursor++; continue; }
                break;
            }
            $named = isset($tokens[$cursor]) && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_STRING;
            $needsDoc = ! $named;
        }
        if ($id === T_CLASS) {
            $cursor = $index - 1;
            $previous = null;
            while ($cursor >= 0) {
                $candidate = $tokens[$cursor];
                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)) { $cursor--; continue; }
                $previous = $candidate;
                break;
            }
            $needsDoc = is_array($previous) && $previous[0] === T_NEW;
        }
        if (! $needsDoc) continue;
        $total++;
        $before = substr($source, max(0, $start - 160), min(160, $start));
        if (! preg_match('/\/\*\*[\s\S]*?\*\/\s*$/', $before)) $missing[] = $file.' near byte '.$start;
    }
}
foreach ($missing as $item) echo '[FAIL] '.$item.PHP_EOL;
echo 'PHP anonymous documented declarations: '.($total - count($missing)).'/'.$total.PHP_EOL;
echo 'PHP anonymous documentation failures: '.count($missing).PHP_EOL;
exit($missing === [] ? 0 : 1);
