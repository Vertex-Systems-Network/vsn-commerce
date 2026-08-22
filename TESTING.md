# VSN Ecommerce Automated Testing

Milestones AS + AT define one repeatable automated and real-browser test contract for local development, Laragon, and CI.

## Fast local suite

After installing PHP dependencies:

```bash
composer test:sqlite
```

This uses the in-memory SQLite configuration in `phpunit.xml` and is the fastest full unit + feature regression suite.

## One-command test matrix

Cross-platform PHP runner:

```bash
php scripts/test-matrix.php
```

Windows PowerShell wrapper:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/test-all-local.ps1
```

Linux/macOS/WSL wrapper:

```bash
bash scripts/test-all-local.sh
```

The runner writes `runtime-artifacts/test-matrix.json` and performs static database/test audits, Composer validation/dependency setup when needed, the SQLite Laravel suite, and the Vite production build.

If `composer.lock` is present, Composer installs the exact locked dependency graph. If it is absent, the runner warns and allows Composer to resolve dependencies so a fresh source package is not blocked before tests start. Commit the generated `composer.lock` before a production release to make dependency resolution reproducible.

## Real MySQL / MariaDB suite

Use a disposable database whose name contains `test`:

```bash
php scripts/mysql-runtime-preflight.php --database=vsn_ecommerce_test --create-database
php scripts/test-matrix.php --mysql
```

or:

```bash
composer test:mysql
```

`tests/TestCase.php` refuses to run MySQL, MariaDB, or PostgreSQL tests against a database name that does not contain `test`.

## PostgreSQL regression suite

Create the dedicated `vsn_test` database, then run:

```bash
composer test:postgres
```

or add `--postgres` to the matrix runner.

## Static-only gate

This does not require Laravel vendor dependencies or a database server:

```bash
php scripts/test-matrix.php --static-only
```

It validates the test-suite contract, MySQL migration preflight, and database portability rules.

## Test isolation rules

All tests inherit the following safety defaults from `tests/TestCase.php`:

- unfaked outbound Laravel HTTP requests are blocked;
- non-SQLite databases must have `test` in the database name;
- Carbon's process-global test clock is reset after every test;
- cache/session/queue/mail use isolated testing drivers from the PHPUnit configuration;
- demo seeding is disabled in every PHPUnit runtime.

Provider integration tests must use `Http::fake()` or fail closed before any request is sent. Tests must never call live Stripe, Twilio, Resend, courier, or KYC endpoints.

## Composer entry points

```text
composer test:unit
composer test:feature
composer test:sqlite
composer test:mysql
composer test:postgres
composer test:static
composer test:matrix
```

## CI contract

`.github/workflows/ci.yml` has independent gates for:

1. static audits + frontend production build;
2. SQLite application tests;
3. MySQL 8.4 application tests;
4. PostgreSQL application tests;
5. Chromium desktop + mobile browser E2E;
6. runtime integration / launch verification after all previous jobs pass.

A database-specific failure therefore identifies the failing runtime instead of being hidden inside one generic test job.


## Browser E2E (Milestone AT)

The Playwright suite uses a dedicated E2E database and never defaults to the normal Laravel development database. The default is `database/e2e.sqlite`; destructive reset scripts refuse any database name/path that does not contain `e2e` or `test`.

Install the pinned runner without changing the frontend lockfile, build the app, install browser binaries, then run:

```bash
npm ci
npm run e2e:bootstrap
npm run e2e:install
npm run build
npm run e2e:run
```

Chromium desktop + mobile only:

```bash
npx playwright test --project=chromium --project=mobile-chromium
```

Cross-browser smoke:

```bash
npm run e2e:cross-browser
```

Browser failures retain trace, screenshot and video evidence under `runtime-artifacts/playwright-results/` and the HTML report under `playwright-report/`.
