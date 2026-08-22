# Database Recovery Runbook

1. Declare incident and stop write traffic if state integrity is uncertain.
2. Select a checksum-verified backup whose timestamp satisfies the business RPO decision.
3. Restore into an isolated database first; never test restore onto production.
4. Verify migrations and critical row counts (users, products, orders, wallet transactions, finance journals).
5. Run finance, provider and migration reconciliation where applicable.
6. Measure actual RTO from incident/recovery start until validated service readiness; record RPO from backup/data point age.
7. Promote only after Operations + Finance approve state integrity.
8. Record drill/incident evidence with `vsn:dr-record` when appropriate.

Never “fix” immutable wallet, finance, tax, shipping-event or audit history using direct UPDATE/DELETE statements.
