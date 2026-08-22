<?php

declare(strict_types=1);

/**
 * VSN Ecommerce MySQL/MariaDB runtime preflight.
 *
 * Runs without Composer/Laravel so a local Laragon/PHP installation can verify
 * database capabilities before `composer install` or `php artisan migrate`.
 *
 * Usage:
 *   php scripts/mysql-runtime-preflight.php
 *   php scripts/mysql-runtime-preflight.php --create-database
 *   php scripts/mysql-runtime-preflight.php --database=vsn_ecommerce_test --create-database
 *   php scripts/mysql-runtime-preflight.php --env=.env --json
 */

$root = dirname(__DIR__);
$options = getopt('', ['env::', 'database::', 'create-database', 'json', 'help']);

if (isset($options['help'])) {
    echo "VSN Ecommerce MySQL runtime preflight\n\n";
    echo "Options:\n";
    echo "  --env=PATH          Environment file (default: .env, fallback .env.example)\n";
    echo "  --database=NAME     Override DB_DATABASE\n";
    echo "  --create-database   Create the database if it does not exist\n";
    echo "  --json              Emit machine-readable JSON\n";
    exit(0);
}

$envPath = isset($options['env']) && is_string($options['env']) && $options['env'] !== ''
    ? $options['env']
    : (is_file($root.'/.env') ? $root.'/.env' : $root.'/.env.example');
if (! str_starts_with($envPath, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $envPath)) {
    $envPath = $root.'/'.ltrim($envPath, '/\\');
}

$readEnv = static /** Inline callback for this operation. */ function (string $path): array {
    if (! is_file($path)) {
        throw new RuntimeException("Environment file not found: {$path}");
    }
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') continue;
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        $values[$key] = $value;
    }
    return $values;
};

$result = [
    'ok' => false,
    'environmentFile' => $envPath,
    'checks' => [],
    'warnings' => [],
    'errors' => [],
];

$addCheck = static /** Inline callback for this operation. */ function (string $name, bool $ok, mixed $details = null) use (&$result): void {
    $row = ['name' => $name, 'ok' => $ok];
    if ($details !== null) $row['details'] = $details;
    $result['checks'][] = $row;
};

try {
    $env = $readEnv($envPath);
    $driver = strtolower((string) ($env['DB_CONNECTION'] ?? 'mysql'));
    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        throw new RuntimeException("DB_CONNECTION must be mysql or mariadb for this preflight; current value is {$driver}.");
    }

    $addCheck('pdo_mysql extension', extension_loaded('pdo_mysql'));
    if (! extension_loaded('pdo_mysql')) {
        throw new RuntimeException('PHP extension pdo_mysql is not loaded. Enable it in the PHP version used by Laragon/CLI.');
    }

    $host = (string) ($env['DB_HOST'] ?? '127.0.0.1');
    $port = (int) ($env['DB_PORT'] ?? 3306);
    $database = isset($options['database']) && is_string($options['database']) && $options['database'] !== ''
        ? $options['database']
        : (string) ($env['DB_DATABASE'] ?? 'vsn_ecommerce');
    $username = (string) ($env['DB_USERNAME'] ?? 'root');
    $password = (string) ($env['DB_PASSWORD'] ?? '');
    $socket = (string) ($env['DB_SOCKET'] ?? '');
    $charset = (string) ($env['DB_CHARSET'] ?? 'utf8mb4');
    $collation = (string) ($env['DB_COLLATION'] ?? 'utf8mb4_unicode_ci');

    if ($database === '' || str_contains($database, "\0")) {
        throw new RuntimeException('DB_DATABASE is empty or invalid.');
    }
    if (! preg_match('/^[A-Za-z0-9_$.-]+$/', $database)) {
        throw new RuntimeException('DB_DATABASE contains unsupported characters for the safe preflight/create path.');
    }

    $baseDsn = $socket !== ''
        ? "mysql:unix_socket={$socket};charset={$charset}"
        : "mysql:host={$host};port={$port};charset={$charset}";
    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $server = new PDO($baseDsn, $username, $password, $pdoOptions);
    $addCheck('server connection', true, $socket !== '' ? ['socket' => $socket] : ['host' => $host, 'port' => $port]);

    $versionRow = $server->query('SELECT VERSION() AS version, @@version_comment AS version_comment')->fetch() ?: [];
    $result['server'] = [
        'driver' => $driver,
        'version' => $versionRow['version'] ?? null,
        'versionComment' => $versionRow['version_comment'] ?? null,
    ];

    $existsStmt = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $existsStmt->execute([$database]);
    $exists = (bool) $existsStmt->fetchColumn();
    $created = false;
    if (! $exists && isset($options['create-database'])) {
        $quotedDatabase = '`'.str_replace('`', '``', $database).'`';
        if (! preg_match('/^[A-Za-z0-9_]+$/', $charset) || ! preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
            throw new RuntimeException('DB_CHARSET or DB_COLLATION contains unsafe characters.');
        }
        $server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET {$charset} COLLATE {$collation}");
        $exists = true;
        $created = true;
    }
    $addCheck('database exists', $exists, ['database' => $database, 'created' => $created]);
    if (! $exists) {
        throw new RuntimeException("Database {$database} does not exist. Re-run with --create-database or create it manually.");
    }

    $dbDsn = $baseDsn.';dbname='.$database;
    $pdo = new PDO($dbDsn, $username, $password, $pdoOptions);

    $schemaStmt = $pdo->prepare('SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $schemaStmt->execute([$database]);
    $schema = $schemaStmt->fetch() ?: [];
    $addCheck('database charset utf8mb4', (($schema['charset'] ?? null) === 'utf8mb4'), $schema);
    if (($schema['charset'] ?? null) !== 'utf8mb4') {
        $result['warnings'][] = 'Database default charset is not utf8mb4. New Laravel tables explicitly use the connection charset, but converting the database default is recommended.';
    }

    $variables = $pdo->query("SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.foreign_key_checks AS foreign_key_checks, @@lower_case_table_names AS lower_case_table_names, @@max_allowed_packet AS max_allowed_packet")->fetch() ?: [];
    $strict = str_contains((string) ($variables['sql_mode'] ?? ''), 'STRICT_TRANS_TABLES') || str_contains((string) ($variables['sql_mode'] ?? ''), 'STRICT_ALL_TABLES');
    $addCheck('strict SQL mode', $strict, $variables['sql_mode'] ?? '');
    if (! $strict) {
        $result['warnings'][] = 'Server session is not strict. Laravel mysql/mariadb connection config forces strict=true, but enabling strict mode server-wide is recommended.';
    }
    $foreignKeys = (int) ($variables['foreign_key_checks'] ?? 0) === 1;
    $addCheck('foreign key checks enabled', $foreignKeys, $variables['foreign_key_checks'] ?? null);
    if (! $foreignKeys) {
        throw new RuntimeException('FOREIGN_KEY_CHECKS is disabled for this session.');
    }
    $result['server']['lowerCaseTableNames'] = isset($variables['lower_case_table_names']) ? (int) $variables['lower_case_table_names'] : null;
    $result['server']['maxAllowedPacket'] = isset($variables['max_allowed_packet']) ? (int) $variables['max_allowed_packet'] : null;
    if ((int) ($variables['max_allowed_packet'] ?? 0) < 16 * 1024 * 1024) {
        $result['warnings'][] = 'max_allowed_packet is below 16 MiB; large imports/media metadata operations may require a larger server setting.';
    }

    // Capability probe mirrors the exact guard pattern that previously failed on
    // some MySQL/MariaDB installs: an indexed generated guard whose expression uses
    // a base column that also participates in a foreign key with ON DELETE SET NULL.
    // VIRTUAL guards must preserve both uniqueness and the FK action.
    $suffix = bin2hex(random_bytes(4));
    $parent = 'vsn_preflight_parent_'.$suffix;
    $child = 'vsn_preflight_child_'.$suffix;
    $qp = '`'.$parent.'`';
    $qc = '`'.$child.'`';
    try {
        $pdo->exec("CREATE TABLE {$qp} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        $pdo->exec("CREATE TABLE {$qc} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL,
            payload JSON NULL,
            active_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN owner_id IS NOT NULL AND status = 'active' THEN owner_id ELSE NULL END) VIRTUAL,
            UNIQUE KEY vsn_preflight_active_guard_uq (active_guard),
            CONSTRAINT vsn_preflight_owner_fk FOREIGN KEY (owner_id) REFERENCES {$qp}(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
        $pdo->exec("INSERT INTO {$qp} (id) VALUES (7)");
        $pdo->exec("INSERT INTO {$qc} (owner_id,status,payload) VALUES (7,'inactive','{\"ok\":true}'),(7,'inactive','{\"ok\":true}'),(7,'active','{\"ok\":true}')");
        $duplicateBlocked = false;
        try {
            $pdo->exec("INSERT INTO {$qc} (owner_id,status,payload) VALUES (7,'active','{\"ok\":true}')");
        } catch (PDOException $e) {
            $duplicateBlocked = ($e->getCode() === '23000') || str_contains(strtolower($e->getMessage()), 'duplicate');
        }
        $pdo->exec("DELETE FROM {$qp} WHERE id = 7");
        $remainingOwnerRefs = (int) $pdo->query("SELECT COUNT(*) FROM {$qc} WHERE owner_id IS NOT NULL")->fetchColumn();
        $capabilityOk = $duplicateBlocked && $remainingOwnerRefs === 0;
        $addCheck('InnoDB + JSON + indexed VIRTUAL guard + FK SET NULL', $capabilityOk, [
            'duplicateBlocked' => $duplicateBlocked,
            'foreignKeySetNullWorked' => $remainingOwnerRefs === 0,
        ]);
        if (! $capabilityOk) {
            throw new RuntimeException('Indexed VIRTUAL generated guard / foreign-key capability probe failed.');
        }
    } finally {
        try { $pdo->exec("DROP TABLE IF EXISTS {$qc}"); } catch (Throwable) {}
        try { $pdo->exec("DROP TABLE IF EXISTS {$qp}"); } catch (Throwable) {}
    }

    $tableStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'");
    $tableStmt->execute([$database]);
    $tableCount = (int) $tableStmt->fetchColumn();
    $result['database'] = ['name' => $database, 'tableCount' => $tableCount, 'charset' => $schema['charset'] ?? null, 'collation' => $schema['collation'] ?? null];

    if ($tableCount > 0) {
        $badCollationStmt = $pdo->prepare("SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND (TABLE_COLLATION IS NULL OR TABLE_COLLATION NOT LIKE 'utf8mb4%') ORDER BY TABLE_NAME");
        $badCollationStmt->execute([$database]);
        $badTables = $badCollationStmt->fetchAll();
        $addCheck('existing tables use utf8mb4', $badTables === [], $badTables);
        if ($badTables !== []) {
            $result['warnings'][] = 'One or more existing tables are not utf8mb4. For a disposable local DB, use `php artisan migrate:fresh --seed` after confirming the target database.';
        }
    }

    $result['ok'] = true;
} catch (Throwable $e) {
    $result['errors'][] = $e->getMessage();
}

$json = isset($options['json']);
if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    echo "VSN Ecommerce MySQL Runtime Preflight\n";
    echo str_repeat('=', 38)."\n";
    foreach ($result['checks'] as $check) {
        echo ($check['ok'] ? '[PASS] ' : '[WARN] ').$check['name'];
        if (isset($check['details']) && is_scalar($check['details'])) echo ': '.$check['details'];
        echo "\n";
    }
    foreach ($result['warnings'] as $warning) echo '[WARN] '.$warning."\n";
    foreach ($result['errors'] as $error) echo '[FAIL] '.$error."\n";
    if (isset($result['server'])) {
        echo 'Server: '.($result['server']['version'] ?? 'unknown').' '.($result['server']['versionComment'] ?? '')."\n";
    }
    echo $result['ok'] ? "Preflight result: PASS\n" : "Preflight result: FAIL\n";
}

exit($result['ok'] ? 0 : 1);
