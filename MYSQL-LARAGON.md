# VSN Ecommerce — MySQL / MariaDB + Laragon Runtime Guide

Milestone AR makes MySQL/MariaDB a first-class runtime for the unified Laravel + React application. PostgreSQL support remains available, but the local `.env.example` defaults are now Laragon-friendly MySQL settings.

## 1. Prepare the environment

From the repository root on Windows Command Prompt:

```bat
copy .env.example .env
```

PowerShell equivalent:

```powershell
Copy-Item .env.example .env
```

Default local database settings are:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vsn_ecommerce
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

Change only the host, port, username, password, or socket if your Laragon installation differs.

## 2. Run the dependency-free MySQL preflight

This check does not require Composer or Laravel vendor files. It verifies the PHP CLI extension and the actual database server capabilities used by VSN Ecommerce.

```bash
php scripts/mysql-runtime-preflight.php --create-database
```

It checks:

- `pdo_mysql` is enabled in the PHP CLI used by Laragon;
- MySQL/MariaDB connectivity and database existence;
- `utf8mb4` database defaults;
- strict SQL mode and foreign-key checks;
- InnoDB, JSON columns, indexed VIRTUAL generated columns, and unique guard semantics;
- existing table collations when a database already contains tables.

If `pdo_mysql` is missing, enable the MySQL PDO extension in the same PHP version Laragon exposes to the terminal, then rerun the preflight.

## 3. Install PHP and frontend dependencies

```bash
composer install
npm install
php artisan key:generate
php artisan optimize:clear
```

`composer install` runs the static MySQL migration audits before Laravel package discovery.

## 4. Create a clean development schema

For a disposable local database:

```bash
php artisan migrate:fresh --seed
```

Do not run `migrate:fresh` against a database containing data you need to preserve.

After migrations, rerun the actual server capability/collation check:

```bash
php scripts/mysql-runtime-preflight.php
```

## 5. Run the normal and real-MySQL test suites

The normal project suite keeps its fast test configuration:

```bash
php artisan test
```

Create a separate disposable MySQL test database:

```bash
php scripts/mysql-runtime-preflight.php --database=vsn_ecommerce_test --create-database
```

Then run the MySQL-specific suite:

```bash
composer test:mysql
```

or directly:

```bash
php artisan test --configuration=phpunit.mysql.xml
```

The MySQL suite verifies the database-level generated unique guards used for active carts, reserved checkout sessions, default saved payment methods, tax jurisdiction normalization, and the single default tax class.

## 6. Build the React application

```bash
npm run build
```

The compiled bundle is written into Laravel `public/build`; there is no separate frontend deployment.

## 7. Backup and restore checks

Application backup creation automatically chooses the active database driver. For MySQL/MariaDB it uses `mysqldump`; PostgreSQL continues to use `pg_dump`.

Create an application-managed backup:

```bash
php artisan vsn:backup-create
```

For a controlled MySQL restore into a database you have already selected:

```bash
VSN_RESTORE_CONFIRM=YES \
BACKUP_FILE=/path/to/verified-backup.sql \
DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=vsn_restore \
DB_USERNAME=root DB_PASSWORD= \
bash scripts/restore-mysql-backup.sh
```

For a destructive restore drill against a separate disposable database:

```bash
SOURCE_DB=vsn_ecommerce RESTORE_DB=vsn_restore bash scripts/backup-restore-drill-mysql.sh
```

The drill compares migration and critical-table row counts after restoration. Never point `RESTORE_DB` at the source/production database.

## 8. AR static database gates

Run all dependency-free static compatibility checks with:

```bash
php scripts/audit-mysql-migrations.php
```

This combines:

- 64-character identifier-name audit;
- migration dependency / foreign-key order audit;
- utf8mb4 index-byte and index-count audit;
- MySQL/MariaDB connection configuration audit;
- database query portability audit;
- PostgreSQL-only raw SQL guard audit.

## Troubleshooting

### `Database connection [mysql] not configured`

Use the Milestone AR `config/database.php`; it contains first-class `mysql` and `mariadb` connections. Clear cached configuration:

```bash
php artisan optimize:clear
```

### `could not find driver`

The PHP CLI running Artisan does not have `pdo_mysql` enabled. Confirm with:

```bash
php -m | findstr /I "PDO pdo_mysql"
```

### Existing development DB was left half-migrated after an older failure

If the database is disposable and you have confirmed the target name:

```bash
php artisan migrate:fresh --seed
```

### MySQL identifier is too long

Run:

```bash
php scripts/audit-mysql-migrations.php
```

AR audits both explicitly named and Laravel-inferred schema identifiers before Composer package discovery.
