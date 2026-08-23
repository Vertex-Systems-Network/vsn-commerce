<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

$options = getopt('', ['static-only', 'mysql', 'postgres', 'skip-frontend', 'skip-install', 'json::']);
$staticOnly = array_key_exists('static-only', $options);
$runMysql = array_key_exists('mysql', $options);
$runPostgres = array_key_exists('postgres', $options);
$skipFrontend = array_key_exists('skip-frontend', $options);
$skipInstall = array_key_exists('skip-install', $options);
$jsonPath = isset($options['json']) && is_string($options['json']) && $options['json'] !== ''
    ? $options['json']
    : 'runtime-artifacts/test-matrix.json';

$results = [];
$failed = false;

/** Handles has command for the test-matrix workflow. */
function hasCommand(string $name): bool
{
    $cmd = PHP_OS_FAMILY === 'Windows' ? 'where '.escapeshellarg($name).' 2>NUL' : 'command -v '.escapeshellarg($name).' 2>/dev/null';

    return trim((string) shell_exec($cmd)) !== '';
}

/** Handles run step for the test-matrix workflow. */
function runStep(string $name, string $command, bool $required = true): void
{
    global $results, $failed;
    echo "\n== {$name} ==\n{$command}\n";
    $started = microtime(true);
    passthru($command, $code);
    $results[] = ['name' => $name, 'command' => $command, 'exitCode' => $code, 'seconds' => round(microtime(true) - $started, 2), 'required' => $required];
    if ($required && $code !== 0) {
        $failed = true;
    }
}

/** Builds the direct PHPUnit command so tests do not depend on Collision's Artisan command. */
function phpunitCommand(string $configuration): string
{
    return escapeshellarg(PHP_BINARY).' '.escapeshellarg('vendor/bin/phpunit').' --configuration='.escapeshellarg($configuration);
}

runStep('Seeder contract audit', escapeshellarg(PHP_BINARY).' scripts/audit-seeders.php');
runStep('Enum integrity audit', escapeshellarg(PHP_BINARY).' scripts/audit-enum-integrity.php');
runStep('Cache readiness audit', escapeshellarg(PHP_BINARY).' scripts/audit-cache-readiness.php');
runStep('PHP test-suite contract audit', escapeshellarg(PHP_BINARY).' scripts/audit-test-suite.php');
runStep('MySQL migration preflight', escapeshellarg(PHP_BINARY).' scripts/audit-mysql-migrations.php');
runStep('Database portability audit', escapeshellarg(PHP_BINARY).' scripts/audit-database-portability.php');
runStep('Performance + security audit', escapeshellarg(PHP_BINARY).' scripts/audit-performance-security.php');

if (! $staticOnly) {
    if (! hasCommand('composer')) {
        fwrite(STDERR, "BLOCKED: Composer is required for Laravel runtime tests.\n");
        $results[] = ['name' => 'Composer availability', 'exitCode' => 2, 'required' => true, 'status' => 'blocked'];
        $failed = true;
    } else {
        runStep('Composer metadata', 'composer validate --no-check-publish');
        if (! $skipInstall && ! is_file('vendor/autoload.php')) {
            if (! is_file('composer.lock')) {
                echo "WARN: composer.lock is absent; Composer will resolve dependencies and create it. Commit the generated lockfile for reproducible CI/releases.\n";
            }
            runStep('PHP dependency install', 'composer install --no-interaction --prefer-dist --no-progress');
        }
        if (is_file('vendor/autoload.php')) {
            runStep('Laravel cache reset', escapeshellarg(PHP_BINARY).' artisan optimize:clear');
            if (! is_file('vendor/bin/phpunit')) {
                fwrite(STDERR, "BLOCKED: vendor/bin/phpunit is unavailable after dependency installation.\n");
                $results[] = ['name' => 'PHPUnit availability', 'exitCode' => 2, 'required' => true, 'status' => 'blocked'];
                $failed = true;
            } else {
                runStep('SQLite unit + feature suite', phpunitCommand('phpunit.xml'));
                if ($runMysql) {
                    runStep('MySQL live preflight', escapeshellarg(PHP_BINARY).' scripts/mysql-runtime-preflight.php --database=vsn_ecommerce_test --create-database');
                    runStep('MySQL full suite', phpunitCommand('phpunit.mysql.xml'));
                }
                if ($runPostgres) {
                    runStep('PostgreSQL full suite', phpunitCommand('phpunit.postgres.xml'));
                }
            }
        } else {
            fwrite(STDERR, "BLOCKED: vendor/autoload.php is unavailable after dependency step.\n");
            $failed = true;
        }
    }

    if (! $skipFrontend) {
        if (! hasCommand('npm')) {
            fwrite(STDERR, "BLOCKED: npm is required for frontend build verification.\n");
            $results[] = ['name' => 'npm availability', 'exitCode' => 2, 'required' => true, 'status' => 'blocked'];
            $failed = true;
        } else {
            if (! $skipInstall && ! is_dir('node_modules')) {
                runStep('Frontend dependency install', is_file('package-lock.json') ? 'npm ci --no-audit --no-fund' : 'npm install --no-audit --no-fund');
            }
            if (is_dir('node_modules')) {
                runStep('Vite production build', 'npm run build');
            } else {
                fwrite(STDERR, "BLOCKED: node_modules is unavailable.\n");
                $failed = true;
            }
        }
    }
}

$dir = dirname($jsonPath);
if ($dir !== '.' && ! is_dir($dir)) {
    @mkdir($dir, 0777, true);
}
file_put_contents($jsonPath, json_encode([
    'generatedAt' => gmdate(DATE_ATOM),
    'status' => $failed ? 'failed' : 'passed',
    'staticOnly' => $staticOnly,
    'mysql' => $runMysql,
    'postgres' => $runPostgres,
    'steps' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

echo "\nTest matrix report: {$jsonPath}\n";
exit($failed ? 1 : 0);
