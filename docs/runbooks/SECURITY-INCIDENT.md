# VSN Commerce Security Incident Runbook

## Scope

Use this runbook for suspected or confirmed unauthorized access, credential compromise, cross-user/cross-vendor data exposure, malicious upload, payment/webhook abuse, secret disclosure, privilege escalation, or integrity compromise.

## Contain

1. Open an incident record and assign a security incident lead.
2. Preserve relevant application, audit, authentication, provider and infrastructure evidence before destructive remediation.
3. Revoke exposed credentials, sessions, API tokens or provider secrets as narrowly and quickly as possible.
4. Disable or isolate the affected feature/provider when continued operation could increase impact.
5. Freeze unrelated production changes and prevent the affected release from being accepted or sealed.

## Investigate

Identify the affected identities, vendors, records, time range, endpoints and release. Verify authorization boundaries independently from UI behavior. Review security events, immutable audit records, webhooks, uploaded-file metadata and provider logs. Determine whether confidentiality, integrity or availability was affected and whether regulated or personal data is involved.

## Eradicate and recover

Patch the root cause, rotate compromised secrets, invalidate affected sessions/tokens and remove malicious artifacts without deleting required evidence. Run targeted regression tests plus the production security, authorization and browser gates. Reconcile payment, order, inventory and provider state when the incident could have altered commerce facts.

## Notification and closure

Escalate legal/privacy notification decisions to the responsible business/privacy owner based on affected jurisdictions and data. Record the decision and evidence; do not guess notification obligations in the incident log. Close only after containment, integrity verification, credential rotation where required, remediation deployment and follow-up ownership are complete.
