# VSN Ecommerce — Production Readiness Layer

This package now exposes the remaining production concerns as explicit architecture, admin UI and WordPress REST surfaces rather than leaving them as informal recommendations.

## Core surfaces added

- `/admin/production-readiness` — launch gates and provider readiness matrix.
- `/legal` — policy/consent center for marketplace, returns, sellers, Game Win, affiliate, coins and KYC.
- Provider integration registry in `wp_vsn_provider_integrations`.
- Signed webhook intake/event log in `wp_vsn_webhook_events`.
- Background job queue in `wp_vsn_background_jobs` with a five-minute WP-Cron worker.
- User session/device registry in `wp_vsn_user_sessions`.
- Security event registry in `wp_vsn_security_events`.
- Per-event notification preferences in `wp_vsn_notification_preferences`.

## New REST endpoints

- `GET /wp-json/vsn/v1/admin/production-readiness`
- `GET|POST /wp-json/vsn/v1/admin/providers`
- `GET /wp-json/vsn/v1/admin/jobs`
- `GET /wp-json/vsn/v1/admin/security-events`
- `GET /wp-json/vsn/v1/sessions`
- `POST /wp-json/vsn/v1/sessions/{id}/revoke`
- `POST /wp-json/vsn/v1/webhooks/{provider}`

## Provider adapters still require credentials

The code intentionally does not fake live integrations. Payment gateways, courier APIs, SMS OTP, KYC/liveness, transactional email, external search, backups/CDN and analytics must be connected by choosing a provider and supplying secrets/configuration.

Webhook signature verification is adapter-based through the WordPress filter `vsn_verify_webhook_signature`. A provider adapter must implement real HMAC/public-key verification before production.

## Required launch QA

- payment/refund reconciliation tests
- courier shipment/RTO/partial-shipment tests
- SMS/KYC/email provider failure tests
- session/2FA/rate-limit/security tests
- accessibility and browser/device testing
- backup + restore rehearsal
- legal review for all special programs
- penetration testing and file upload validation
## Laravel payment milestone note

The Laravel backend now includes a payment-intent ledger, provider abstraction and signed sandbox webhook path. This does **not** mean a live card processor is connected. `VSN_CARD_PAYMENTS_ENABLED` and `VSN_SANDBOX_PAYMENT_SIMULATOR_ENABLED` are safe-off in the example environment. Production requires a real provider adapter, official provider signature verification, secret rotation, reconciliation/alerting and refund testing.


## Milestone E wallet readiness

The Laravel wallet now uses immutable transaction/entry records, holds for checkout, signed-payment settlement for purchases, idempotent transfers, and daily check-in rewards. Before production, execute the feature suite against PostgreSQL + Redis and configure a real payment provider. Game Win and Gifts now use server-owned eligibility/cost rules and immutable wallet actions; keep the generic wallet surface limited to explicit, validated operations and never expose an arbitrary debit endpoint. Gift scheduled-delivery targets still require a real courier/fulfillment adapter before they can be promised as exact carrier delivery times.


## Milestone F affiliate readiness

Laravel now owns affiliate enrollment, referral ancestry, L1-L10 commission calculation, maturity scheduling and immutable wallet settlement. Production launch still requires counsel-approved program terms, fraud/identity monitoring and migration reconciliation from WordPress. Milestone H now implements proportional affiliate reversal for partial refunds and remaining-only recovery on a later full chargeback; the original wallet ledger is never rewritten.


## Milestone G Game Win readiness

Laravel now owns Game Win campaign timing, coin entry debits, immutable entries, committed draw secret, canonical participant snapshot, deterministic winner selection, cancellation refunds and fulfillment audit. The draw proof is reproducible after secret reveal. For production in regulated markets, publish/anchor each commitment hash to an external append-only timestamped system before entries open, add jurisdiction/age/geography eligibility controls, and obtain legal review/licensing where required. Courier/prize shipment execution remains a later fulfillment integration.


## Returns/refunds boundary (Milestone H)

Laravel now owns line-item return requests, 30-day configurable eligibility, inventory restock, online payment refund ledger entries, VSN Coin refunds, seller payable/commission reversals and marketplace dispute outcomes. Production still requires a real PSP refund adapter, courier return-label/scan integration, evidence-file storage, and a real COD/bank payout execution rail. COD cash refunds remain `manual_payment_required` until finance explicitly confirms payment.

## Milestone L shipping readiness

Laravel now owns seller-sub-order shipment creation, tracking projection, immutable carrier events, signed/replay-protected webhook intake, seller dispatch/delivery SLA measurement, RTO states and delivery-driven finance settlement reconciliation. The bundled `sandbox` courier is intentionally development-only and throws in production. Production launch still requires a real courier adapter, official provider webhook verification, production label storage/access rules, provider reconciliation/alerting, and real-world tests for pickup failures, RTO, address corrections and carrier outages. The current implementation supports one active outbound shipment per seller sub-order; multi-package/partial shipment splitting remains a later extension if operations require it.


## Milestone O payment vault
- Laravel saved payment methods use provider tokens only; raw PAN/CVC storage is prohibited.
- Payment-method mutation requires device-bound password step-up.
- Live card vaulting remains blocked until a production payment provider adapter is configured.


## Milestone S
Tax/VAT/GST engine and immutable tax invoicing/credit-note snapshots are now part of the Laravel modular monolith. Rates remain admin-configured rather than hard-coded.


## Milestone T — Risk operations

- Keep `VSN_RISK_AUTO_HOLD_CRITICAL=false` until thresholds have been calibrated against real traffic.
- Review open risk cases and active holds from `/admin/risk`.
- Tune wallet/payment/game/return velocities for launch geography and expected customer behavior.
- Treat shared device/payment/phone matches as investigation signals, not identity proof.
- Monitor false-positive rates before enabling stricter automated enforcement.


## Milestone U — Reporting operations

- Keep report export storage private and configure lifecycle/backups separately from public product media.
- Run report generation on queue/worker infrastructure for production rather than relying only on web requests.
- Set `VSN_REPORT_MAX_EXPORT_ROWS` and dashboard date limits based on PostgreSQL/read-replica capacity.
- For high marketplace volume, move heavy BI queries to a read replica/warehouse; do not compromise OLTP checkout/payment latency.
- Treat promotion return-on-discount as attribution, not causal ROI, until an experimentation/incrementality framework exists.
- Review report access/audit controls before granting Finance/Admin roles.

### Milestone U runtime note
The bundled BI dashboard currently reads authoritative OLTP tables directly with bounded date ranges. This is intentional for the current architecture, not a claim that the primary database should serve unlimited analytical workloads. Benchmark before launch and move heavy reporting to a read replica/warehouse when required.

## Milestone V — production hardening

The Laravel backend now has sanitized liveness/readiness endpoints, Redis queue heartbeat monitoring, failed-job persistence/monitoring, structured JSON production logging, request correlation IDs, global API rate limiting, slow-query fingerprints, private driver-aware MySQL/MariaDB/PostgreSQL backup/restore workflow, deployment/CI scripts, scheduler/worker heartbeats, queue pressure monitoring, and an Admin Operations health panel.

Production should run the scheduler every minute and at least one Redis queue worker for `critical,default,notifications,reports`. Horizon is recommended on Linux/WSL but remains optional so native Windows development is not blocked by its process-control extension requirements.


## Milestone W — runtime integration and launch gate

Milestone W adds `compose.yaml`, PostgreSQL-backed feature-test execution, stateful auth API smoke, worker/scheduler heartbeat checks, backup-to-disposable-restore rehearsal, a runtime verification manifest, and `php artisan vsn:launch-gate`.

The launch gate is fail-closed for production settings, dependency lock evidence, database/runtime health, provider readiness and verified backups. Do not override a blocker merely to make the dashboard green; either satisfy the dependency or explicitly change the product scope (for example, keep card payments disabled).

See `RUNTIME-INTEGRATION.md` for the verification workflow.

## Milestone X live-provider gate

Production readiness now distinguishes configuration, active provider health and reconciliation evidence. Stripe card/vault, Twilio SMS, Resend email, courier and KYC adapters are implemented, but Launch Gate remains blocked until live credentials are configured and probes succeed. Payment/shipping/KYC also require a recent reconciliation run with zero mismatch/error counts. Provider secrets are never returned by the readiness API.


## Final Production Acceptance (Milestone Z)

A technically green Launch Gate does not by itself authorize public traffic. Final acceptance requires a recent DR restore drill meeting configured RTO/RPO, no open SEV1/SEV2 incidents, private sensitive storage, bounded retention, required operator runbooks, and four recorded sign-offs: Operations, Security/Privacy, Finance, Business Owner. Use `php artisan vsn:acceptance` and `/admin/acceptance`.

When legacy decommission is part of the closure policy, set `VSN_ACCEPTANCE_REQUIRE_LEGACY_DECOMMISSION=true` only after the cutover is complete. The decommission workflow records archive checksum, source read-only evidence, migration bridge removal, migration-secret rotation and verification; it never remotely deletes WordPress.

## Android/mobile API (Milestone AA)

Before Android production traffic, complete `docs/android/ANDROID-GO-LIVE-CHECKLIST.md`, run `scripts/android-api-smoke.sh` against staging, configure Android version/store settings, and configure Google/Facebook callback/App Link settings if social login is enabled. FCM token registration alone does not mean push delivery is live; a production FCM notification provider must also be configured.
