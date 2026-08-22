# Security & Privacy Production Acceptance

This is an engineering acceptance checklist, not jurisdiction-specific legal advice.

Required engineering evidence:
- production debug disabled and HTTPS enforced;
- KYC documents, message attachments, private reports and database backups use non-public storage;
- retention windows are bounded for behavioral views and report exports;
- admin mutations are audited and financial/security history remains append-only where designed;
- payment PAN/CVC never enters VSN storage;
- provider secrets are supplied only through deployment secrets/environment;
- open SEV1/SEV2 incidents block acceptance;
- a recent restore drill meets declared RTO/RPO targets;
- relevant legal/policy text has separate counsel/business approval.

Do not turn “privacy review passed” into a claim of legal compliance in every market. Tax, raffle/game, KYC, privacy and consumer-law obligations remain jurisdiction dependent.
