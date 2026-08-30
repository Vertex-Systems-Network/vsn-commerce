# P3-A — Remaining Route Verification Queue

Status: **OPEN — MUST REACH ZERO BEFORE P3-A ACCEPTANCE**

This queue is deliberately separate from the finding ledger. A route remains here whenever backend permission mapping exists but component-level behavior has not yet been fully classified as read-only, capability-aware, contradictory or capability-gap.

Removing a row requires source evidence for API methods/paths, backend permission, read vs mutation controls, and relevant loading/error/destructive semantics. Do not remove rows from inference.

## Already source-verified positive controls

- `/admin/access` — read-only RBAC inspection under `users.view`; no mutation surface.
- `/admin/compliance` — capability-aware secondary permissions.
- `/admin/acceptance` — capability-aware manage/sign/seal split.

## Already source-verified defects

The following no longer need generic verification; they are classified in the acceptance ledger and supporting audits:

- `/admin`
- `/admin/users`
- `/admin/vendors`
- `/admin/catalog`
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

## Remaining route/component verification

### Q-01 — `/admin/catalog/new`

Entry permission is `catalog.manage`. Verify the editor's supporting read dependencies (`/admin/catalog`, `/tax/classes`, media-library calls) do not create a hidden cross-domain permission contradiction for a principal that legitimately has catalog management authority.

### Q-02 — `/admin/catalog/:id/edit`

Entry permission is `catalog.manage`. Verify product read/update/media-library/media attachment paths and supporting tax/catalog metadata dependencies under the complete editor lifecycle.

### Q-03 — `/admin/orders`

Entry permission is `orders.view`. Source appears list/search/read oriented; verify no hidden mutation control and confirm search/filter/loading/error/pagination contract.

### Q-04 — `/admin/returns`

Entry permission is `returns.view`. Verify list-level component is read-only or correctly capability-aware; keep detail mutation finding C-P3A-009 separate.

## Cross-cutting verification still required after queue reaches zero

Route verification alone does not close P3-A. Final audit acceptance must also record:

1. exact later repair owner/path budget per finding group;
2. no duplicate/contradictory finding IDs across audit artifacts;
3. parent matrix reconciled to the final classifications;
4. exact-head CI + CodeQL + Governance Code Scanning PASS;
5. SELF REVIEW or independent review provenance;
6. audit/planning-only merge, followed by a fresh later implementation branch.

## Stop rule

P3 runtime/RBAC/UI repair remains unauthorized while any Q-row is open or while the parent matrix is unreconciled.