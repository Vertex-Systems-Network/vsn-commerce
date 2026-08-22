# SEV1 Incident Runbook

SEV1 means material security exposure, corrupted/duplicate financial state, widespread checkout/auth outage, destructive data loss, or another event requiring immediate executive/technical response.

1. Declare incident and create an `incident_records` entry; preserve correlation IDs and timestamps.
2. Assign Incident Commander, Operations Lead, Communications Lead, and Scribe. Avoid one person making all decisions.
3. Contain: disable only the affected capability when possible (card payments, wallet transfers, games, payouts). Do not destroy evidence.
4. Preserve DB snapshots, provider webhook payload hashes, logs, deployment SHA and queue state.
5. For financial incidents, stop new mutation paths before attempting reconciliation. Never edit immutable ledgers directly.
6. If customer data may be exposed, follow counsel-approved privacy/breach notification procedures for the applicable jurisdiction. Do not guess statutory notification deadlines from this runbook.
7. Recover from a known-good release/data point, validate reconciliations, then restore traffic gradually.
8. Resolve the incident record only after containment, customer-impact review, reconciliation and owner approval.
9. Complete a blameless post-incident review with concrete preventative actions.
