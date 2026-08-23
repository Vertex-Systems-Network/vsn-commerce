# VSN Commerce Database Recovery Runbook

## Preconditions

Database recovery is a controlled incident operation. Assign an incident commander and database owner, stop unsafe application writes when integrity is uncertain, and preserve the current database and logs before restoration whenever feasible.

## Select recovery point

1. Identify the affected release, migration batch and incident start time.
2. Select a checksum-verified private backup within the required RPO.
3. Record backup ID, storage location, SHA-256, completion/verification times and expected database engine/version.
4. Confirm the proposed restore point with the incident commander and business owner when recovery can discard accepted writes.

## Restore procedure

1. Restore into an isolated environment first when time and incident conditions permit.
2. Verify backup checksum before import.
3. Restore schema and data using the engine-specific approved tooling.
4. Apply only migrations required for the target release; never mark migrations as complete without executing or intentionally reconciling them.
5. Run integrity checks for users, vendors, products, inventory, carts, orders, payments, refunds, returns, settlements and audit records.
6. Reconcile external payment/shipping state for transactions that may have occurred after the restore point.
7. Run application readiness, PHPUnit smoke coverage and authenticated commerce smoke paths before reopening writes.

## Validation

Recovery is successful only when database health and migration checks pass, critical foreign-key/business invariants hold, the active artifact is compatible with the restored schema, and financial/provider reconciliation reports no unexplained mismatches.

Record actual RTO/RPO, restored backup hash, migration state, reconciliation evidence and any intentionally replayed events. Feed the result into the disaster-recovery drill/incident evidence used by production acceptance.
