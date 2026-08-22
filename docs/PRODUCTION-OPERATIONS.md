# VSN Ecommerce — Production Operations (Milestone AW)

Milestone AW turns the existing health, backup, provider and acceptance primitives into a repeatable production release workflow. The production domain is deliberately not encoded in the repository; operators provide environment-specific URLs and secrets through the shared `.env`.

## Release layout

Recommended Linux layout:

```text
/var/www/vsn/
├── current -> /var/www/vsn/releases/<release>
├── releases/
│   ├── <previous-release>/
│   └── <new-release>/
└── shared/
    ├── .env
    ├── storage/
    ├── runtime/
    └── deployments/
```

The release script never copies `.env`, `storage`, `runtime`, `vendor`, or `node_modules` from the source package. `.env`, storage and runtime evidence are shared across releases.

## Required release evidence

Every deployment can persist:

- release ID
- previous release
- source commit SHA
- release-tree/artifact SHA-256
- verified pre-migration backup
- migration batch before/after
- maintenance usage
- current deployment phase
- completion or failure evidence
- rollback target evidence

Use `deployment_runs` for DB evidence and `shared/deployments/*.jsonl` for a pre-migration filesystem journal.

## Production environment

Start from `.env.production.example`, then supply real values outside version control.

Production launch expects at minimum:

- `APP_ENV=production`
- `APP_DEBUG=false`
- HTTPS application/front-end URLs
- non-empty `APP_KEY`
- `VSN_DEMO_SEED_ENABLED=false`
- Redis cache and queue
- required scheduler and queue-worker heartbeats
- queue-pressure health enabled
- private backups enabled
- sandbox payment/shipping/SMS simulators disabled
- a concrete `VSN_RELEASE`
- a committed `composer.lock`

Run:

```bash
php artisan vsn:production-config-audit
```

This is a configuration gate, not a provider-health substitute. Provider health and reconciliation remain enforced by `vsn:launch-gate`.

## First production bootstrap

Create shared state first:

```bash
sudo mkdir -p /var/www/vsn/{releases,shared/storage,shared/runtime,shared/deployments}
sudo chown -R <deploy-user>:www-data /var/www/vsn
cp .env.production.example /var/www/vsn/shared/.env
```

Generate and retain a real Composer lockfile before production deployment:

```bash
composer update --no-install
```

Review the lockfile, commit it, and build/test the exact locked dependency set in CI.

The first AW deployment creates `deployment_runs` and `incident_events`. The filesystem JSONL deployment journal exists before that migration, so the first release still has pre-migration evidence.

## Release command

From an extracted candidate source tree:

```bash
export VSN_RELEASE=<release-id>
export VSN_DEPLOY_ROOT=/var/www/vsn
export VSN_COMMIT_SHA=<40-char-git-sha>          # optional but recommended
export VSN_ARTIFACT_SHA256=<64-char-sha256>      # optional; tree hash generated if omitted

./scripts/release-production.sh
```

`release-production.sh` performs:

1. staged release copy
2. shared `.env`, `storage`, and `runtime` links
3. source + production configuration preflight
4. locked Composer install
5. locked npm install and Vite build
6. checksum-verified pre-migration DB backup
7. maintenance mode on current release
8. forward-only `php artisan migrate --force`
9. release evidence initialization
10. optimized Laravel caches
11. atomic `current` symlink switch
12. queue/Horizon restart and scheduler interrupt
13. maintenance exit
14. bounded wait for real scheduler + queue-worker heartbeats and healthy readiness
15. launch gate
16. deployment completion evidence

If your process manager requires an explicit restart, set a reviewed command in:

```bash
VSN_DEPLOY_SERVICE_RESTART_COMMAND=
```

Do not place untrusted request/user input in this operator-controlled value.

## Rollback

If a post-switch release phase fails, `VSN_DEPLOY_AUTO_ROLLBACK=true` (default in the production template) switches the application symlink back to the prior release when it is still available. Manual application rollback:

```bash
./scripts/rollback-production.sh <previous-release> [deployment-id]
```

The script:

- enters maintenance mode
- atomically points `current` to the previous release
- refreshes Laravel caches
- restarts workers
- exits maintenance
- validates operations status
- optionally records rollback evidence

### Database rollback policy

AW intentionally **does not run `migrate:rollback` automatically**. Production migrations must be backward compatible for at least one application release so that an application rollback remains safe. If a database reversal is necessary, write and review a forward data-safe corrective migration. Never blindly roll back finance/order migrations against live data.

## Scheduler and queue workers

Choose one scheduler mechanism only.

### Cron

Install `deploy/cron/vsn-scheduler`; it now points to the merged application root:

```text
/var/www/vsn/current
```

### systemd timer

Use:

- `deploy/systemd/vsn-scheduler.service`
- `deploy/systemd/vsn-scheduler.timer`

The timer executes one `schedule:run` tick each minute, avoiding a long-lived scheduler process holding old release code after a symlink switch.

### Queue worker

Use either:

- `deploy/systemd/vsn-worker.service`
- `deploy/supervisor/vsn-worker.conf`
- Horizon example when Horizon is intentionally installed/configured

Queue workers publish their running release in `operational_heartbeats`. Readiness fails when a required worker reports an old release after deployment.

## Health and observability

Public liveness:

```text
GET /api/v1/health
```

Readiness:

```text
GET /api/v1/health/ready
```

Admin details:

```text
GET /api/v1/admin/system/operations
```

Readiness includes database, cache, Redis, storage, migrations, scheduler heartbeat, queue-worker heartbeat and optional queue-pressure enforcement.

CLI snapshot:

```bash
php artisan vsn:ops-status
```

## Backups and restore

Production releases require a checksum-verified backup before migration when backups are enabled.

Useful commands:

```bash
php artisan vsn:backup-create
php artisan vsn:backup-verify <backup-id>
php artisan vsn:backup-prune
```

Restore drills remain isolated from the live database:

```bash
./scripts/backup-restore-drill-mysql.sh
# or PostgreSQL drill script when PostgreSQL is selected
```

Record completed DR evidence:

```bash
php artisan vsn:dr-record passed <rto-minutes> <rpo-minutes> --backup=<backup-id>
```

## Incident command

Open:

```bash
php artisan vsn:incident-open sev2 payments "Payment provider degradation" --summary="Elevated failure rate"
```

Timeline updates:

```bash
php artisan vsn:incident-note <incident-id> "Provider escalation opened"
php artisan vsn:incident-status <incident-id> investigating "Root cause investigation active"
php artisan vsn:incident-status <incident-id> monitoring "Metrics recovered; monitoring"
php artisan vsn:incident-resolve <incident-id> "Provider stable and reconciliation clean"
```

`incident_events` are append-only. Unresolved SEV1/SEV2 incidents block the launch gate.

## Admin operations console

`/admin/operations` now exposes:

- detailed health
- production configuration blockers
- launch-gate status
- backup evidence
- deployment history
- release/phase/artifact/backup evidence
- active incidents and recent incident events
- incident note/status/resolve controls
- failed queue jobs
- existing finance/reconciliation operations

## Pre-go-live sequence

Recommended sequence:

```bash
php artisan vsn:production-config-audit
php artisan vsn:providers-probe
php artisan vsn:providers-reconcile
php artisan vsn:db-index-audit
php artisan vsn:launch-gate
php artisan vsn:acceptance
php artisan vsn:go-live-gate
```

Do not bypass a blocking gate by changing persisted evidence. Fix the failing runtime condition, rerun the relevant probe/reconciliation, and then rerun the gate.
