# VSN Commerce Go-Live Operator Handbook

## Purpose

This runbook defines the minimum operator procedure for promoting a VSN Commerce release to production. A release is not approved because a deployment completed; it is approved only when the production acceptance service reports zero blockers, all required sign-offs are recorded, and the exact release candidate is sealed.

## Before deployment

1. Confirm the intended release identifier and commit SHA.
2. Confirm `composer.lock` and `package-lock.json` are committed and their SHA-256 values match the deployment evidence when production lock enforcement is enabled.
3. Confirm the latest database backup is checksum-verified and within the configured launch age limit.
4. Confirm database migration, portability, performance/security, PHPStan, Pint, PHPUnit, production build, browser E2E and required runtime verification evidence are green.
5. Confirm payment, payment-vault, shipping, SMS, email and KYC providers required by enabled production features have fresh healthy probes and clean reconciliation evidence.
6. Confirm there are no unresolved SEV1/SEV2 incidents.
7. Confirm the rollback owner and incident commander for the release window.

## Deployment sequence

1. Put the application into the deployment mode required by the release plan.
2. Record the pre-deployment migration batch and verified backup identifier.
3. Deploy the exact immutable artifact represented by the release evidence.
4. Run migrations once and record the resulting migration batch.
5. Clear/rebuild application caches as defined by the deployment workflow.
6. Start or verify scheduler and queue workers for the deployed release.
7. Execute application smoke checks and authenticated browser checks.
8. Run provider health and reconciliation checks for all enabled external providers.
9. Generate the final runtime verification manifest for the exact release and artifact hash.
10. Run production acceptance, collect the required independent sign-offs, and seal the release candidate.

## Go-live decision

Proceed only when the canonical go-live status reports all of the following:

- current blockers: 0;
- acceptance evidence is fresh;
- every required sign-off is approved;
- the sealed release candidate hashes match current release evidence.

Warnings must be reviewed and explicitly accepted by the responsible owner. A warning is not permission to ignore a blocker.

## Immediate rollback triggers

Rollback or stop rollout when any of the following occurs during the configured rollback window:

- database migration or integrity failure;
- authentication or authorization regression;
- payment capture, refund, settlement or payout integrity failure;
- inventory oversell or order-total inconsistency;
- sustained application 5xx errors or failed readiness probes;
- security/privacy incident or suspected cross-user/cross-vendor data exposure;
- provider failure that makes an enabled critical purchase path unsafe;
- release evidence no longer matches the deployed artifact.

Use the database recovery, provider outage, security incident or SEV1 runbook as applicable.

## Evidence to retain

Retain deployment run ID, release/commit/artifact hashes, dependency-lock hashes, backup ID and checksum, migration batches, runtime verification hash, production acceptance run ID, sign-offs, sealed manifest hash, provider reconciliation IDs, incident references and rollback outcome if any.
