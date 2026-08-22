# VSN Ecommerce — Unified Laravel + React Application
## Local demo access

After `php artisan migrate:fresh --seed`, use **Super Admin** `admin@example.test` / `ChangeMe12345` for full admin-panel data entry. Customer: `customer@example.test`; Seller: `seller@example.test`; all primary demo accounts use the same local-only password. See `LOGIN-CREDENTIALS.md` for Support, Moderator, Finance and Admin accounts. Demo accounts are disabled in production.


This repository is now **one deployable application**, not a separate backend and frontend.

- Laravel application: repository root (`app/`, `routes/`, `database/`, `artisan`)
- React UI: `resources/js/`
- Blade SPA shell: `resources/views/app.blade.php`
- Built frontend assets: `public/build/`
- Customer/vendor/admin UI and `/api/v1` are served by the **same Laravel origin**
- Android uses `/api/mobile/v1` plus the same `/api/v1` business APIs

## Local setup

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php scripts/mysql-runtime-preflight.php --create-database
php artisan migrate --seed
composer dev
```

`composer dev` starts Laravel and Vite HMR from this same project. On Windows you can also run `start-dev.bat`. Open the URL configured for your local Laravel application.


## Demo login accounts

After `php artisan migrate:fresh --seed`:

- Customer: `customer@example.test` / `ChangeMe12345`
- Seller: `seller@example.test` / `ChangeMe12345`
- Super Admin: `admin@example.test` / `ChangeMe12345`

These are development-only seed accounts. Change/remove them before production.

The browser now has visible Sign in/Create account actions, session-aware protected routes, role-based post-login redirects, a dedicated Seller Center and a dedicated Admin Panel. See `AUTH-ADMIN-ACCEPTANCE.md`.

## Production build

```bash
npm ci
npm run build
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

Point the web server document root to `public/`. Laravel serves both the React application and the APIs. There is no separate frontend deployment.

## Docker

```bash
make runtime-up
```

The `app` image contains PHP dependencies **and** the compiled React bundle. Worker and scheduler reuse the same image.

## MySQL / MariaDB runtime

MySQL is the default local database connection and MariaDB is also first-class. Before the first migration, run the dependency-free server preflight and the static migration audits:

```bash
php scripts/mysql-runtime-preflight.php --create-database
composer audit:mysql-migrations
php artisan migrate:fresh --seed
php scripts/mysql-runtime-preflight.php
```

For the dedicated real-MySQL database tests, create `vsn_ecommerce_test` and run `composer test:mysql`. `composer install` also runs the static migration preflight automatically before Laravel package discovery. See `MYSQL-LARAGON.md` and `MYSQL-MIGRATION-PREFLIGHT.md`.

## Seller Center

A user with role `seller` and a linked vendor opens `/vendor`. The Seller Center now contains overview, products, inventory, orders, shipping, returns, promotions, finance, payouts, analytics, verification, tax/invoices and store settings. The development seeder provides `seller@example.test` / `ChangeMe12345`.

## Milestone AQ — unified UI/UX

The storefront, customer account, Seller Center and Admin Panel now share a consistent responsive UX layer. Mobile/tablet navigation uses slide-in drawers; forms, tables, loading/error/empty states, pagination, modals, confirmations, toasts, focus treatment and reduced-motion behavior are standardized through `resources/js/components/Toolkit.jsx` and `resources/js/components/UXProvider.jsx`.


## Milestone AR — MySQL zero-error runtime pass

MySQL/MariaDB now have first-class Laravel connections, Laragon-friendly local defaults, a dependency-free live server preflight, MySQL equivalents for PostgreSQL partial-unique invariants, driver-aware operations index auditing/backups, a dedicated MySQL PHPUnit configuration, and explicit MySQL restore/drill scripts. PostgreSQL support remains available.


## Milestone AT — browser E2E

Real-browser Playwright coverage now exercises Customer, Seller, Support, Moderator, Finance, Admin and Super Admin login/RBAC, customer COD checkout, seller fulfilment and return feedback, moderator review resolution, finance payouts, admin returns/settings, and mobile navigation. Run `npm run e2e:bootstrap`, `npm run build`, then `npm run e2e:run`. The default E2E database is isolated at `database/e2e.sqlite`; reset guards require `e2e` or `test` in any database name/path. See `TESTING.md`.


## Milestone AU — Android API final

Android mobile access tokens are now bound to the original installation ID on every `/api/v1` business request, rotated refresh-token replay revokes the compromised mobile session, semantic app-version/device headers are enforced, and the notification pipeline can deliver Android push through FCM HTTP v1 using a service-account OAuth token. FCM registrations are encrypted, timestamped, removable and automatically retired on invalid/unregistered responses. The Kotlin/Retrofit sample, OpenAPI contract, Postman collection and Android smoke test cover the final lifecycle. See `docs/android/ANDROID-API-GUIDE.md`.

## Milestone AS — full automated test pass

Automated testing is now split into deterministic SQLite, MySQL 8.4, and PostgreSQL application suites, with global outbound-HTTP isolation and a guard that refuses to run destructive database tests against non-test database names. Use `php scripts/test-matrix.php` for the cross-platform local runner, `php scripts/test-matrix.php --mysql` for Laragon/MySQL verification, or `php scripts/test-matrix.php --static-only` when Composer/database services are unavailable. Full commands and safety rules are documented in `TESTING.md`.

## Milestone AV — Performance & Security

The application includes request/query performance telemetry, configurable performance budgets, production CSP/security headers, endpoint-class rate limiting, request/upload caps, secure upload inspection, versioned catalogue caching, production N+1 logging, database performance indexes and React route-level lazy loading.

Release audit:

```bash
composer audit:performance-security
```

Operational guidance is in `docs/PERFORMANCE-SECURITY.md`.

## Milestone AW — Production operations

Production release, rollback, incident, worker/scheduler, backup and readiness procedures are documented in `docs/PRODUCTION-OPERATIONS.md`. The supplied deployment examples use a release symlink layout and the merged Laravel application root; there is no separate `backend/` deployment.

## Milestone AX — Final production acceptance

The final acceptance flow binds the exact deployed artifact to runtime/static/browser/Android evidence, four independent immutable approvals and a final release-candidate seal. See `docs/FINAL-PRODUCTION-ACCEPTANCE.md`.

## Milestone AY — runtime acceptance

Use `docs/RUNTIME-ACCEPTANCE.md` after AX. AY binds the Composer/npm lockfile hashes into deployment and final acceptance evidence and provides Windows/Linux strict runtime acceptance commands. Production remains blocked until a real reviewed `composer.lock` exists and the runtime evidence chain passes.

## Milestone AZ — go-live stabilization

After the accepted release candidate is deployed and `php artisan vsn:go-live-gate` returns READY, open the monitored production launch window with `scripts/go-live.sh` or `scripts/go-live.ps1`. The scheduler records immutable stabilization observations every five minutes. See `docs/GO-LIVE-STABILIZATION.md` for rollback windows, thresholds, incident handling, and post-launch sign-offs.

## Zero-to-end fresh-install verification

For seeders, migrations, Unit/Feature tests, cache clear/cache rebuild and frontend production build in one safe pass, run `php scripts/zero-to-end.php`. It uses a dedicated database whose name must contain `test`; see `INSTALLATION-VERIFICATION.md`.