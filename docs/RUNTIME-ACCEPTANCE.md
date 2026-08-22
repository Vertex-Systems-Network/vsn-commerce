# VSN Ecommerce — AY Runtime Acceptance & Go-Live Blocker Closure

Milestone AY does not add marketplace features. It closes the gap between a statically clean source release candidate and a production candidate whose exact dependency graph and runtime environment have been verified.

## Why AY exists

AX correctly leaves production blocked when `composer.lock` is absent. Composer `install` without a lock resolves dependencies at install time, so two deployments from the same source can receive different transitive versions. AY makes that impossible to sign off as production-ready.

The final release identity now includes:

```text
release
+ commit SHA
+ application artifact SHA-256
+ composer.lock SHA-256
+ package-lock.json SHA-256
+ runtime capability evidence SHA-256
+ final automated verification SHA-256
+ acceptance evidence SHA-256
```

## Step 1 — Resolve composer.lock on a controlled machine

Do **not** resolve dependencies on production.

### Windows / Laragon

```powershell
powershell -ExecutionPolicy Bypass -File scripts/resolve-composer-lock.ps1
```

### Linux / WSL / CI

```bash
bash scripts/resolve-composer-lock.sh
```

The resolver:

1. refuses `APP_ENV=production`;
2. requires Composer v2;
3. performs a real Composer dependency resolution;
4. writes `composer.lock`;
5. runs strict Composer validation;
6. records the resulting SHA-256 in `runtime-artifacts/composer-lock-resolution.json`.

Review the generated lockfile and commit it. Production deployment refuses a missing lock.

## Step 2 — Runtime capability gate

Dependency-free inspection:

```bash
php scripts/runtime-capability-audit.php
```

Strict gate:

```bash
php scripts/runtime-capability-audit.php --strict --json=runtime-artifacts/runtime-capabilities.json
```

It checks PHP version/extensions, Composer, Node/npm, dependency locks, npm lock alignment, supported PDO driver availability, and Laravel writable directories.

## Step 3 — Core runtime acceptance

### Windows / Laragon

```powershell
powershell -ExecutionPolicy Bypass -File scripts/runtime-acceptance.ps1
```

### Linux / WSL

```bash
bash scripts/runtime-acceptance.sh
```

The command fails closed before dependency installation if runtime capability blockers remain. Once clean it runs locked dependency installation, frontend build, Laravel SQLite regression, and the final static/operations/security acceptance audits.

For the dedicated MySQL suite:

```powershell
$env:VSN_AY_RUN_MYSQL='1'
powershell -ExecutionPolicy Bypass -File scripts/runtime-acceptance.ps1
```

Use only the dedicated `vsn_ecommerce_test` database for destructive test execution.

## Step 4 — Manual/tagged CI release candidate

`.github/workflows/release-candidate.yml` is a release-only workflow. It refuses to run a candidate without committed `composer.lock` and `package-lock.json`, then records:

- release ID
- commit SHA
- composer lock SHA-256
- npm lock SHA-256
- source tree SHA-256

Normal development CI remains separate from this release-candidate gate.

## Step 5 — Deployment binding

`release-production.sh` records both lock hashes in:

- `deployment_runs`
- `runtime/release-metadata.json`

Final acceptance recomputes the local hashes and requires them to match the deployed metadata. Dependency drift after deployment invalidates acceptance.

## Step 6 — Final evidence and production seal

On the deployed candidate:

```bash
./scripts/final-production-acceptance.sh
```

This now starts with strict runtime capability verification. The final acceptance manifest requires:

- runtime capability audit PASS
- dependency locks bound to deployment
- launch verification PASS
- static acceptance PASS
- browser E2E PASS
- Android deployed smoke PASS
- matching release / commit / artifact / lock fingerprints

Then complete the four independent sign-offs, seal the candidate, and run:

```bash
php artisan vsn:rc-seal
php artisan vsn:go-live-gate
```

## Current source-package blocker

This source package intentionally does **not** contain a fabricated `composer.lock`. The artifact-generation runtime cannot resolve Packagist DNS and has no Composer binary, so a trustworthy lock cannot be generated here.

The blocker is closed only by a real Composer resolution on a connected controlled machine followed by review and commit of the generated lockfile.
