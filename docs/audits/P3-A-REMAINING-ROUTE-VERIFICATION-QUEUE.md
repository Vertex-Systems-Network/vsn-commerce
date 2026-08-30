# P3-A — Remaining Route Verification Queue

Status: **ZERO OPEN ROUTES — MATRIX/ACCEPTANCE RECONCILIATION STILL REQUIRED**

This queue is deliberately separate from the finding ledger. A route remains open only while backend permission mapping exists but component-level behavior has not yet been classified as read-only, capability-aware, contradictory or capability-gap.

## Source-verified positive controls

- `/admin/access` — read-only RBAC inspection under `users.view`; no mutation surface.
- `/admin/compliance` — capability-aware secondary permissions.
- `/admin/acceptance` — capability-aware manage/sign/seal split.
- `/admin/orders` — list-level component is read-only; pagination/list-completeness defect is C-P3A-029.
- `/admin/returns` — list-level component is read-only; pagination/list-completeness defect is C-P3A-030. Detail mutations remain separately classified by C-P3A-009.

## Source-verified defects

All other protected admin route surfaces have concrete classifications in the acceptance ledger and supporting audit artifacts, including:

- `/admin`
- `/admin/users`
- `/admin/vendors`
- `/admin/catalog`
- `/admin/catalog/new`
- `/admin/catalog/:id/edit`
- `/admin/promotions`
- `/admin/loyalty`
- `/admin/games`
- `/admin/tax`
- `/admin/reviews`
- `/admin/media`
- `/admin/risk`
- `/admin/analytics`
- `/admin/orders/:id`
- `/admin/shipping`
- `/admin/payments`
- `/admin/returns/:id`
- `/admin/finance`
- `/admin/payouts`
- `/admin/notifications`
- `/admin/settings`
- `/admin/operations`
- `/admin/seller-quality`
- `/admin/production-readiness`

## Closed queue evidence

### Q-01 / Q-02 — catalog new/edit

Closed by `docs/audits/P3-A-CATALOG-EDITOR-CAPABILITY-AUDIT.md`.

Findings:

- C-P3A-027 — mandatory reusable media-library read introduces `catalog.manage` → `media.view` cross-domain dependency.
- C-P3A-028 — reusable media-library upload/archive controls require `media.manage` but are presented inside the catalog editor without that capability boundary.

### Q-03 — `/admin/orders`

Closed by `docs/audits/P3-A-ORDER-RETURN-LIST-CONTRACT-AUDIT.md`.

The list surface is read-only under `orders.view`; no hidden list mutation was found. C-P3A-029 records the dropped server pagination contract / list-completeness defect.

### Q-04 — `/admin/returns`

Closed by `docs/audits/P3-A-ORDER-RETURN-LIST-CONTRACT-AUDIT.md`.

The list surface is read-only under `returns.view`; no hidden list mutation was found. C-P3A-030 records the dropped server pagination contract / list-completeness defect. Detail mutations remain C-P3A-009.

## Cross-cutting acceptance still required

**Zero open Q-rows does not by itself accept P3-A.** Final audit acceptance still requires:

1. parent 30-route matrix reconciled to every final classification;
2. no duplicate/contradictory finding IDs across audit artifacts;
3. concrete later repair owner/path budget for each finding group;
4. final exact-head CI + CodeQL + Governance Code Scanning PASS;
5. SELF REVIEW or independent review provenance;
6. audit/planning-only merge;
7. runtime repair to start later from a fresh branch after accepted audit merge.

## Stop rule

P3 runtime/RBAC/UI repair remains unauthorized until the parent matrix and acceptance ledger are reconciled and the final audit carrier passes its own acceptance gates.