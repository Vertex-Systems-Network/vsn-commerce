# VSN Commerce SEV1 Incident Runbook

## When to declare SEV1

Declare SEV1 for an active event with severe customer, financial, security or platform impact, including widespread checkout failure, corrupted order or inventory state, material payment/refund errors, cross-tenant exposure, production unavailability, or another condition requiring immediate coordinated response.

## First actions

1. Assign an incident commander and a communications owner.
2. Open an incident record with severity `sev1`, start time, affected release and current impact.
3. Freeze unrelated production changes.
4. Preserve logs, deployment IDs, provider event IDs and relevant request/correlation identifiers.
5. Determine whether the safest action is rollback, traffic reduction, feature disablement or provider isolation.
6. If confidentiality or integrity may be affected, also invoke the Security Incident runbook.

## Stabilize

Prioritize customer and data safety over diagnosis speed. Stop unsafe writes when integrity cannot be guaranteed. For commerce-impacting incidents verify independently: order totals, inventory reservations, payment state, refunds, vendor settlements and notification side effects before resuming normal traffic.

## Diagnose

Build a timestamped timeline from observable evidence. Compare the active release and artifact hashes with the last known-good deployment. Check application/readiness errors, failed jobs, queue pressure, database health, migrations and enabled provider health/reconciliation. Do not mutate historical financial or audit records to make symptoms disappear.

## Recover

Use an explicit rollback or forward-fix decision recorded by the incident commander. Database recovery must follow `DATABASE-RECOVERY.md`; provider isolation must follow `PROVIDER-OUTAGE.md`. After recovery, run smoke, authenticated E2E and integrity checks before reopening the affected path.

## Close

Resolve the incident only after impact has ended and data integrity is verified. Record root cause, contributing factors, customer/financial impact, remediation, evidence links and follow-up owners. A SEV1/SEV2 incident must not remain open when production acceptance is signed.
