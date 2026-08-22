# VSN Ecommerce — Go-Live Operator Handbook

## Rule of launch
Production traffic is allowed only when `php artisan vsn:launch-gate` has zero blockers **and** a persisted Production Acceptance run has zero blockers plus all four approvals: Operations, Security/Privacy, Finance, and Business Owner. Screenshots or verbal approval are not substitutes for database evidence.

## T-24 hours
1. Freeze release scope. No feature work.
2. Run the full runtime verification stack and archive the generated manifest.
3. Probe and reconcile live payment, courier and KYC providers.
4. Create and checksum-verify a private backup for the active database driver (MySQL/MariaDB or PostgreSQL).
5. Complete an isolated restore drill and record actual RTO/RPO evidence.
6. Review failed jobs, unresolved SEV1/SEV2 incidents, risk holds, finance reconciliation, provider mismatch counts, and legacy migration reconciliation.

## T-60 minutes
1. Confirm DNS/CDN/LB rollback values are documented.
2. Confirm queue workers and scheduler heartbeat are fresh.
3. Run `php artisan vsn:launch-gate`.
4. Run `php artisan vsn:acceptance`.
5. Obtain four acceptance sign-offs in `/admin/acceptance`.
6. Run `php artisan vsn:go-live-gate`; this re-evaluates current blockers and requires a fresh approved acceptance for the same release/environment.
7. Record exact release ID and operator names in the change ticket.

## Launch sequence
1. Deploy immutable application release.
2. Run migrations before opening public traffic.
3. Restart queue workers and scheduler.
4. Run provider probes/reconciliation.
5. Run launch gate again after deploy.
6. Shift traffic gradually where infrastructure supports it.
7. Observe authentication, checkout, payment webhooks, courier events, queues, DB latency and error rates.

## Abort criteria
Immediately stop rollout for payment amount mismatches, duplicate financial postings, database corruption, mass authentication failure, data exposure, inability to process refunds, unavailable backups, or any unresolved SEV1.

## First 24 hours
