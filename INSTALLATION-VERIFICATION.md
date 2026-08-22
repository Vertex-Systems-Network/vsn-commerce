# VSN Ecommerce — Strict Installation & Release Verification

This file records the strict 12-point verification contract for the cleaned VSN Ecommerce Laravel + React source package.

## 1. Project isolation — PASS

The release source contains no runtime files from unrelated projects and no obsolete platform tree. Automated isolation checks reject known foreign-project identifiers and forbidden directories.

- Project isolation: **24/24 PASS**
- Active frontend legacy API calls: **0**
- Forbidden source directories (`.figma`, obsolete platform tree, `HOTFIX`, separate `backend/`): **absent**
- Historical milestone/hotfix/source artifact filenames: **0**

## 2. Proper file naming — PASS

PHP named types are checked against their filenames and temporary/copy naming patterns are rejected.

- Named PHP types checked: **588**
- Filename failures: **0**
- Historical milestone suffixes were removed from tests, migrations, runtime-acceptance and go-live operator scripts.

Run:

```bash
php scripts/audit-file-names.php
```

## 3. Function/class documentation — PASS

Documentation gates cover named and anonymous code boundaries.

- Named PHP class/interface/trait/enum/function/method declarations: **2940/2940 documented**
- PHP anonymous functions/arrow functions/anonymous classes: **1117/1117 documented**
- JavaScript/React/TypeScript class/function/method/callback declarations: **2122/2122 documented**
- Kotlin declarations: **67/67 documented**

Run:

```bash
composer audit:documentation
```

or without Composer:

```bash
php scripts/audit-code-documentation.php
php scripts/audit-anonymous-documentation.php
node scripts/audit-code-documentation.cjs
php scripts/audit-kotlin-documentation.php
```

## 4. Zero-to-install verification — STATIC PASS / RUNTIME BLOCKED IN ARTIFACT CONTAINER

Dependency-free zero-to-end verification executes successfully:

```bash
php scripts/zero-to-end.php --static-only
```

Result: **PASS**.

Full runtime verification is intentionally fail-fast and requires Composer, `mbstring`, `pdo_mysql`, a reachable MySQL server, and npm registry/cache access. The current artifact container does not provide those runtime prerequisites, so a full Laravel/MySQL install is **BLOCKED here, not claimed as PASS**.

On Laragon run:

```powershell
php scripts/zero-to-end.php
```

The verifier uses the isolated database `vsn_ecommerce_zero_test` by default and refuses a database name without `test`.

## 5. Migration test — STATIC PASS / REAL MYSQL INCLUDED IN ZERO-TO-END

Static migration verification:

- Migration files: **45**
- Tables audited: **142**
- Foreign-key dependency/order checks: **296**
- MySQL identifier definitions: **865**
- Identifiers over MySQL 64-char limit: **0**
- Largest estimated utf8mb4 B-tree key: **1200 / 3072 bytes**
- Database portability: **PASS**
- MySQL generated partial-unique guards are indexed **VIRTUAL** columns, avoiding the previously discovered MySQL FK/generated-column 1215 restriction.

Real runtime zero-to-end performs:

```text
migrate:fresh --seed
migrate
```

against the dedicated MySQL test database.

## 6. Unit tests — CONTRACT PASS / REAL PHPUNIT REQUIRES COMPOSER

- PHPUnit Unit test methods: **16**
- Feature test methods: **404**
- Total PHPUnit test methods: **420**
- Critical test domains: **14/14**
- Dependency-free pure unit smoke: **11/11 PASS**

The real PHPUnit Unit suite is executed by zero-to-end after Composer dependencies are installed:

```bash
php artisan test --testsuite=Unit
```

## 7. Seed tests — STATIC PASS / REAL DOUBLE-SEED INCLUDED IN ZERO-TO-END

- Seeder audit: **67/67 PASS**
- Backed enums discovered: **37**
- Enum-cast models: **34**
- Invalid literal enum assignments: **0**
- Demo review uses `ReviewStatus::Approved`
- Vendor settlement payout reservation uses `VendorSettlementStatus::PayoutPending`
- Demo data is gated by `VSN_DEMO_SEED_ENABLED`; production disables demo seeding.

Real zero-to-end runs seeding twice:

```text
php artisan migrate:fresh --seed
php artisan db:seed
```

The second pass verifies seeder idempotency.

## 8. npm test — PASS

`npm test` is an explicit dependency-free frontend source contract and was executed successfully.

It checks package-lock alignment, relative imports, source isolation, historical artifacts and required login documentation.

Result: **0 failures**.

## 9. npm build test — BLOCKED BY ARTIFACT NETWORK/CACHE

The requested clean dependency/build sequence is:

```bash
npm ci
npm test
npm run build
npm test
```

In the current artifact environment `npm ci --offline` returns `ENOTCACHED` because the npm package cache is empty, and external registry access times out/DNS fails. Therefore Vite dependencies cannot be installed here and a production build is **not falsely reported as PASS**.

The zero-to-end verifier runs the complete sequence automatically when npm dependencies are reachable.

## 10. npm test again — PASS

The dependency-free `npm test` was executed again after cleanup/renames and returned **0 failures**.

After a successful Vite build, zero-to-end runs `npm test` a third time automatically to verify that the build process did not mutate source/package contracts.

## 11. Extra files removed — PASS

Removed from the clean release source:

- unrelated/obsolete platform implementation
- Figma metadata/pasted source artifacts
- historical milestone/hotfix/validation/source files
- obsolete legacy-migration runtime subsystem
- historical milestone names in test/migration/operator-script filenames
- temporary `node_modules/`
- temporary `runtime-artifacts/`
- separate `backend/`

Runtime evidence directory `runtime/` is intentionally retained because production acceptance writes release verification manifests there.

## 12. Login credentials file — PASS

The root file `LOGIN-CREDENTIALS.md` contains the requested local/demo credentials.

Primary accounts:

```text
Full Super Admin / complete data entry
Email: admin@example.test
Password: ChangeMe12345
Landing: /admin

Operational Admin
Email: ops-admin@example.test
Password: ChangeMe12345
Landing: /admin

Seller
Email: seller@example.test
Password: ChangeMe12345
Landing: /vendor

Customer
Email: customer@example.test
Password: ChangeMe12345
Landing: /account
```

These demo accounts are only seeded when:

```env
VSN_DEMO_SEED_ENABLED=true
```

Production must keep demo seeding disabled and create a real Super Admin with `php artisan vsn:admin-create`.

## Current source verification summary

```text
PHP syntax files:                 702
PHP syntax errors:                  0
PHPUnit test files:                58
PHPUnit test methods:             420
PHPUnit Unit test methods:         16
Feature test methods:             404
Seeder audit:                 67/67 PASS
Cache readiness:              24/24 PASS
Project isolation:            24/24 PASS
Named PHP docs:           2940/2940 PASS
Anonymous PHP docs:       1117/1117 PASS
JS/React docs:            2122/2122 PASS
Kotlin docs:                  67/67 PASS
Filename failures:                 0
Migrations:                        45
MySQL identifiers:               865
Over-64 identifiers:               0
Performance/security:         44/44 PASS
Production operations:        54/54 PASS
Final acceptance:             55/55 PASS
Go-live stabilization:        65/65 PASS
npm test:                          PASS
npm test failures:                   0
```

## Current artifact-runtime blockers

The current verification container reports these external/runtime blockers:

1. PHP `mbstring` extension missing
2. Composer v2 binary missing
3. `composer.lock` cannot be generated here because Composer/package network is unavailable
4. No supported PDO database driver is loaded
5. npm dependency cache is empty and external npm registry access is unavailable

These are environment/dependency acquisition blockers. They are intentionally not converted into false migration/PHPUnit/build PASS results.

## Direct URL / Apache routing regression

The package now includes both `public/.htaccess` and a repository-root Laragon compatibility `.htaccess`. Direct URLs such as `/login`, `/account/orders`, `/vendor/orders`, and `/admin/users` must reach Laravel instead of returning an Apache 404. Core `/api/v1/*` and `/sanctum/csrf-cookie` paths use the same front-controller rewrite.

Vite production builds use `/build/` as their public base because output is stored under `public/build`. `npm run build` now runs `scripts/verify-built-assets.mjs` and fails when a compiled JavaScript chunk contains the broken bare `/assets/` prefix.

Run after replacing an older package/build:

```powershell
php artisan optimize:clear
npm ci
npm run build
npm test
```

For Laragon/Apache, prefer a virtual-host document root pointing at `<project>/public`. See `docs/ROUTING-DEPLOYMENT.md`.


## Media Library, user-scoped data and seller storefront verification

The current release adds a reusable Media Library rather than relying on per-form image URLs. Administrators can manage global or seller-scoped images, sellers can use their own and marketplace-global images, product editors can attach library images before or after the first product save, and seller storefront logos can be selected from the Media Library. Shared library binaries are not deleted when detached from one product; unused archived library assets are removed from storage.

Seller storefronts now expose a controlled `/shop/{slug}` public URL, storefront visibility/headline/description/public support email, and a copyable share link. `/vendors` provides the public active-store directory. Public seller responses do not expose internal support email or private operational settings.

Customer data remains server-authoritative and user-scoped. Orders, wallet, wishlist, notifications, returns and addresses are guarded by authenticated user ownership; bundled personal mock orders/messages/notifications/identity have been removed. Laravel mode no longer substitutes static demo games/products/stores when a live API returns zero rows or fails.

Current feature-specific source gate:

```text
Marketplace media/storefront: 58/58 PASS
Frontend npm test:                  PASS
Direct routing audit:          28/28 PASS
Project isolation:             24/24 PASS
Seeder audit:                  67/67 PASS
Cache readiness:               24/24 PASS
Automated tests declared:          420
PHPUnit Unit methods:                16
Migrations:                           45
MySQL identifiers:                   865
API controller references:           334
Missing controller methods:            0
```

The artifact environment still cannot complete `composer install`, MySQL runtime migrations/PHPUnit, or `npm ci && npm run build` because Composer/PDO/mbstring and external npm registry access are unavailable here. Those are not reported as runtime PASS. `php scripts/zero-to-end.php` remains the authoritative Laragon end-to-end command once those prerequisites are available.
