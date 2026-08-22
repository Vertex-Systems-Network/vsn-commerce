# VSN Ecommerce — AZ Go-Live & Post-Launch Stabilization

AZ is the controlled cutover/stabilization block after AY/AX runtime acceptance. It does not add marketplace features.

## Preconditions

Do not open a launch window until the exact release candidate has:

- committed/reviewed `composer.lock` and aligned `package-lock.json`;
- strict runtime acceptance PASS;
- deployed release evidence + verified backup;
- browser E2E + deployed Android smoke evidence;
- four pre-launch acceptance sign-offs;
- immutable RC seal;
- `php artisan vsn:go-live-gate` = READY.

## Open the window

Linux/WSL:

```bash
./scripts/go-live.sh
```

Windows/Laragon:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/go-live.ps1
```

This creates one active launch window per environment and freezes the exact release/RC/artifact/dependency/runtime evidence.

## Automatic observations

The scheduler runs:

```text
vsn:go-live-observe --active
```

every five minutes. Each observation is append-only evidence.

Observed production facts include:

- release/RC identity;
- full operational readiness and worker release match;
- technical launch gate;
- provider health/freshness;
- latest finance reconciliation;
- SEV1/SEV2/SEV3 incidents;
- failed queue jobs;
- failed/pending notification deliveries;
- orders/gross activity since launch;
- paid/failed payment attempts and failure percentage.

The first blocking observation can automatically open a SEV2 `go_live_stabilization` incident. Operators must resolve the incident after remediation; it is never auto-resolved.

## Default stabilization policy

```text
Rollback window:                 120 minutes
Minimum stabilization duration: 240 minutes
Observation interval:             5 minutes
Consecutive healthy observations: 6
Failed jobs allowed:              0
Failed notifications allowed:     0
Notification backlog maximum:   500
Payment failure threshold:       10% after at least 10 attempts
Open SEV3 warning threshold:       2
```

All values are configurable with `VSN_GO_LIVE_*` environment settings.

No-order activity is a warning, not a blocker, so a low-volume launch can still stabilize if technical/financial evidence stays healthy.

## Finite operator watch

Linux/WSL:

```bash
./scripts/post-launch-watch.sh <window-id> 60
```

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/post-launch-watch.ps1 -Window <window-id> -Minutes 60
```

These commands are finite foreground watches, not background jobs. They stop immediately on a blocking observation.

## Rollback

If the application release must be rolled back during the launch window:

```bash
./scripts/go-live-rollback.sh <previous-release> <window-id> [deployment-id]
```

The wrapper uses the AW application rollback, then records immutable launch-window rollback evidence. Database migrations are still never blindly reversed.

## Stabilization sign-offs

After the configured stabilization time, a fresh healthy observation and the configured number of consecutive healthy observations are required. Default post-launch approvals:

- Operations
- Finance
- Business Owner

Production requires distinct authorized signers. When every post-launch sign-off is approved, the window becomes `stable` and closes.

A rejected stabilization sign-off terminates the window as `failed`; it cannot be rewritten. Open a new acceptance/go-live sequence after remediation.

## CLI

```bash
php artisan vsn:go-live-open
php artisan vsn:go-live-observe --active
php artisan vsn:go-live-status [window-id]
php artisan vsn:go-live-signoff <window-id> <operations|finance|business_owner> <user-email> --comment="..."
php artisan vsn:go-live-rollback-record <window-id> <target-release> "reason/evidence"
```

## Admin UI

`/admin/acceptance` includes the post-launch window, latest blockers/warnings, rollback-window state, consecutive healthy observations and stabilization sign-offs.

Finance has `acceptance.view` + `acceptance.sign` only. Admin can manage acceptance/go-live observations. Super Admin retains the final seal/business-owner authority.
