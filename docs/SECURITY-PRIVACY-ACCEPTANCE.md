# VSN Commerce Security & Privacy Acceptance

## Purpose

This checklist is the human acceptance companion to automated security and production gates. It does not replace automated evidence and cannot override a blocking check.

## Identity and authorization

- Customer records are scoped to the authenticated user.
- Seller operations are scoped to the seller's linked vendor and cannot cross vendor boundaries.
- Admin capabilities are enforced server-side through RBAC, not inferred from frontend navigation.
- Sensitive operations requiring step-up authentication retain device/session binding and expiry controls.

## Sensitive data and storage

- KYC documents, private message attachments, report exports and backups use non-public storage.
- Raw card numbers/CVC are prohibited; saved-payment identities use provider tokens and encrypted persistence.
- Secrets and provider credentials are supplied through deployment secrets/environment configuration and are not committed to the repository.
- Behavioral/product-view and export retention periods remain within configured acceptance limits.

## Uploads and browser security

- Sensitive upload flows pass the secure upload inspector, MIME/size/image checks and ownership validation.
- CSP, anti-framing and content-type protections remain enabled for production pages/API responses as designed.
- Browser acceptance includes runtime error, failed request, accessibility and HTML conformance gates.

## Commerce integrity

- Payment/webhook signatures and idempotency are enforced.
- Refund, payout, wallet, gift, promotion and game ledger actions remain idempotent and auditable.
- Shipping events cannot regress lifecycle state or cross order/vendor ownership boundaries.
- Immutable audit/financial facts are not rewritten to hide corrections.

## Acceptance evidence

Before approving `security_privacy`, review the exact release/artifact hashes, automated security scans, authorization tests, production configuration audit, unresolved security incidents, provider health/reconciliation evidence and any risk exceptions. Record material exceptions in the acceptance comment or linked incident/risk record.

Approval is valid only for the evidence hash and release candidate that was reviewed. Any material evidence change requires a new acceptance run.
