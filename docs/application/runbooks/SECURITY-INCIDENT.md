# Security Incident Runbook

## Suspected credential/token compromise
- Revoke/rotate the affected secret in the provider first, then update the secret manager and redeploy.
- Invalidate affected sessions/devices when required.
- Do not paste secrets into tickets, chat, incident evidence or audit comments.

## Suspected account takeover
- Apply scoped risk hold, revoke sessions/devices, preserve security-event evidence and require step-up recovery.
- Do not permanently ban solely on a heuristic risk score.

## Suspected data exposure
- Restrict access, preserve logs, identify exact fields/users/time window, engage security/privacy owners and counsel.
- KYC files, private reports, message attachments and backups must never be moved to public storage for investigation convenience.

## Closure
Record root cause, affected population, rotations/revocations, evidence references and corrective controls. Any legal notification decision must be jurisdiction/counsel approved.
