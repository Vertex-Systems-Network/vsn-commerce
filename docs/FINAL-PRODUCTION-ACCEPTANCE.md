# VSN Ecommerce — Final Production Acceptance (Milestone AX)

AX is the last release decision layer. It does not replace tests, launch gates, deployment evidence, restore drills or independent approval; it binds them to one exact release candidate.

## What is frozen

A persisted production acceptance snapshot records:

- release ID and environment
- completed deployment ID
- release artifact SHA-256
- final automated verification SHA-256
- canonical acceptance-evidence SHA-256
- automated checks and blocker/warning counts
- evaluation timestamp

If release/artifact/runtime evidence changes after the snapshot, new sign-offs are rejected and a new acceptance run must be created.

## Final evidence chain

The final verification manifest is assembled from four evidence classes:

1. `runtime/launch-verification.json` — runtime dependencies, migrations, Laravel tests, build, application smoke, authenticated E2E, real scheduler/worker heartbeat, restore drill and provider contracts.
2. `runtime-artifacts/static-acceptance.json` — MySQL migration, DB portability, performance/security, production operations, auth/admin and test-suite source audits.
3. `runtime-artifacts/browser-e2e.json` — successful Chromium desktop/mobile browser E2E from CI for the deployed commit.
4. `runtime-artifacts/android-api-smoke.json` — successful device-binding/refresh-replay/authenticated-commerce Android smoke against the deployed API.

Deployment creates `runtime/release-metadata.json` containing release, deployment ID, commit SHA and artifact SHA-256. `scripts/final-acceptance-evidence.php` rejects evidence from another release/commit and writes:

```text
runtime/final-acceptance-verification.json
```

Production acceptance additionally verifies that its `artifactSha256` matches the completed `deployment_runs` row.

## Evidence preparation

Static evidence:

```bash
VSN_COMMIT_SHA=<deployed-commit> ./scripts/final-static-acceptance.sh
```

Deployed Android smoke:

```bash
export VSN_ANDROID_API_BASE_URL=https://<production-host>
export VSN_ANDROID_TEST_EMAIL=<dedicated-acceptance-user>
export VSN_ANDROID_TEST_PASSWORD=<secret>
export VSN_COMMIT_SHA=<deployed-commit>
./scripts/android-api-smoke.sh
```

Obtain `browser-e2e.json` from the successful CI `vsn-browser-e2e` artifact for the same commit and copy it into the controlled acceptance evidence directory.

Aggregate:

```bash
php scripts/final-acceptance-evidence.php \
  --launch=runtime/launch-verification.json \
  --release-metadata=runtime/release-metadata.json \
  --static=runtime-artifacts/static-acceptance.json \
  --browser=runtime-artifacts/browser-e2e.json \
  --android=runtime-artifacts/android-api-smoke.json \
  --output=runtime/final-acceptance-verification.json
```

## Automated final gate

```bash
./scripts/final-production-acceptance.sh
```

This aggregates final evidence and runs configuration, health, provider probe/reconciliation, launch gate and production acceptance.

## Four independent sign-offs

Production template enables:

```env
VSN_ACCEPTANCE_REQUIRE_DISTINCT_SIGNERS=true
```

Required areas:

1. **Operations** — deployment, health, backup/restore, queue/scheduler/provider operational evidence.
2. **Security / Privacy** — access controls, security controls, private storage, retention and incident posture.
3. **Finance** — payment/refund/payout/reconciliation/tax/invoice financial integrity.
4. **Business Owner** — final commercial/business authorization.

The same user cannot sign two areas when distinct signers are required. Sign-offs are immutable in the Eloquent model and at MySQL/MariaDB/PostgreSQL DB level.

## Final RC seal

After all four approvals:

```bash
php artisan vsn:rc-seal
```

or use **Seal release candidate** in `/admin/acceptance` as Super Admin.

The immutable `release_candidate_manifests` row binds:

- deployment
- artifact SHA-256
- runtime verification SHA-256
- acceptance evidence SHA-256
- four approved sign-offs
- sealed timestamp

A human-readable copy is written to the configured `VSN_RELEASE_CANDIDATE_MANIFEST` path.

## Go-live decision

```bash
php artisan vsn:go-live-gate
```

`READY` requires all of the following at that moment:

- zero current automated blockers
- fresh approved acceptance run
- all four approvals
- exact artifact/runtime evidence still matching
- immutable RC seal matching the approved snapshot

If any condition changes, the gate returns blocked.

## Never bypass the gate

Do not edit DB evidence, acceptance JSON, sign-offs, RC manifests or health records to force green status. Fix the underlying condition, regenerate evidence, create a new acceptance snapshot and repeat independent approvals.
