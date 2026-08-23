# VSN Commerce Provider Outage Runbook

## Scope

Use this runbook when an enabled payment, payment-vault, shipping, SMS, email or KYC provider is unavailable, degraded, returning inconsistent state, or failing reconciliation.

## Assess

1. Identify provider type/code, first failure time, affected operations and customer impact.
2. Compare application errors with provider health and reconciliation evidence.
3. Determine whether failures are read-only/degraded or can create unsafe duplicate financial/fulfillment side effects.
4. Open an incident when severity thresholds are met and preserve provider request/event IDs.

## Contain

Disable or isolate only the affected capability when a safe alternate path exists. Do not silently reroute financial or identity operations to an unverified provider. For payment uncertainty, stop retries that could duplicate capture/refund operations until idempotency/provider state is confirmed. For shipping uncertainty, preserve tracking/event ordering and do not synthesize delivery state.

## Recover

1. Verify fresh provider health.
2. Run provider reconciliation for the affected time window.
3. Resolve mismatches explicitly and retain evidence of every correction.
4. Replay only idempotent events/operations whose final state is known.
5. Re-enable the provider gradually and monitor error rates, queue backlog and business state.

## Release impact

An enabled production provider without fresh healthy evidence or a recent clean reconciliation blocks launch where required by the launch gate. Do not override this by manually editing acceptance records.
