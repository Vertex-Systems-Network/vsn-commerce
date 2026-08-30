# P3-A — Admin Operations Capability Audit

Status: **SOURCE-VERIFIED / PLANNING ONLY**

Working lane: `agent/20260830-vsn-commerce-abd-196`
Parent matrix: `docs/audits/P3-A-ADMIN-DOMAIN-CONTRACT-MATRIX.md`
Source: `resources/js/pages/AdminOperations.jsx`

## Scope

This slice audits read-vs-mutation presentation for the operations-oriented admin components already backend-mapped in the parent matrix. It does not authorize runtime, RBAC, API, schema or UI repair.

Backend remains the security authority. The defects below are capability-model/UI correctness defects unless separately proven to bypass backend enforcement.

## C-P3A-006 — order detail uses hard-coded roles instead of backend permissions

`AdminOrderDetail` derives operation access from role names:

- order operation: `admin` / `super_admin`
- COD finance action: `finance` / `admin` / `super_admin`

The backend contracts are permission-based (`orders.manage` and finance-domain authority), not React role-name authority.

Impact:

- a principal with a valid delegated capability can be hidden from an action merely because its role string is not in the component list;
- a role-name check can drift from later backend permission changes or explicit permission revocation;
- UI and API authorization are governed by different models.

Classification: **ROLE_CAPABILITY_DRIFT**

Later repair: render protected actions from `hasPermission(...)` using the same permission identifiers enforced by the backend. Do not broaden backend permissions to preserve the current role-name UI.

## C-P3A-007 — Shipping renders manage actions to view-only principals

Route entry: `shipping.view`.

`AdminShipping` reads shipment/quality data and unconditionally exposes mutation actions when row state allows them:

- retry label creation;
- provider sync;
- shipment cancel.

The parent backend reconciliation maps writes to `shipping.manage`.

Classification: **CAPABILITY_GAP**

Later repair: preserve read-only shipment visibility while requiring `shipping.manage` for mutation controls.

## C-P3A-008 — Payments exposes provider sync to view-only principals

Route entry: `payments.view`.

`AdminPayments` loads payment data and exposes provider synchronization without a component permission check. Backend write authority is `payments.manage`.

Classification: **CAPABILITY_GAP**

Later repair: gate reconciliation/sync controls on `payments.manage`; retain backend signed-webhook/settlement authority unchanged.

## C-P3A-009 — Returns detail exposes financial/workflow mutations to view-only principals

Route entry: `returns.view`.

The return-detail component exposes state-dependent mutation controls including:

- approve/reject return review;
- warehouse receive/inspection;
- refund retry;
- manual refund confirmation;
- dispute resolution.

No component capability check was found around these controls in the reviewed source. Backend writes are mapped to `returns.manage` / the corresponding protected admin write authority.

Classification: **CAPABILITY_GAP / FINANCIAL_ACTION_PRESENTATION**

Later repair: gate mutation panels/actions using the exact backend capability and retain server-side idempotency/audit/financial validation as authority.

## C-P3A-010 — Finance reconciliation is exposed on a view route without `finance.manage`

Route entry: `finance.view`.

`AdminFinanceCenter` reads `/admin/finance` and renders `Run reconciliation`, which POSTs to `/admin/finance/reconcile`, without checking `finance.manage`.

Classification: **CAPABILITY_GAP**

Later repair: `finance.view` retains ledger visibility; `finance.manage` controls the reconciliation mutation.

## C-P3A-011 — Payout lifecycle mutations are exposed on a view route

Route entry: `finance.view`.

`AdminPayouts` exposes multiple high-impact mutations without a component `finance.manage` check, including:

- verify/unverify payout destination;
- approve/reject payout;
- mark paid/failed;
- retry/cancel;
- create payout batch.

These actions affect financial state and must not rely on backend 403 as the first capability signal shown to a view-only user.

Classification: **CAPABILITY_GAP / FINANCIAL_ACTION_PRESENTATION**

Later repair: separate view and manage presentation. Preserve backend maker/checker, idempotency and ledger authority wherever those contracts already exist; this audit does not claim they are absent.

## C-P3A-012 — Notification broadcast/retry actions are exposed on a view route

Route entry: `notifications.view`.

`AdminNotifications` exposes:

- create/send broadcast;
- retry failed/disabled delivery.

No `notifications.manage` presentation gate was found in the reviewed component.

Classification: **CAPABILITY_GAP**

Later repair: require `notifications.manage` for broadcast/retry controls while retaining `notifications.view` for campaign/delivery visibility.

## C-P3A-013 — Settings writes are exposed on a view route

Route entry: `settings.view`.

`AdminSettings` exposes save controls for store, order/returns, catalog and operations groups; the component does not check `settings.manage` before rendering those mutation controls.

Classification: **CAPABILITY_GAP**

Later repair: provide a genuinely read-only settings view for `settings.view`; render edit/save controls only with `settings.manage`.

## Pattern conclusion

The reviewed file demonstrates a repeated local anti-pattern:

`route *.view` → component renders read + write controls → backend rejects unauthorized writes with `*.manage`.

This is not evidence that backend RBAC is bypassed. It is evidence that capability-aware presentation is incomplete across several admin domains.

The repository already has better local patterns in Compliance and Acceptance. Later repair should reuse the existing `useAuth().hasPermission(...)` model rather than introducing a new client authorization framework.

## Proposed later bounded repair grouping

Planning only; not implementation authorization.

1. **P3-RBAC-UI-A — non-financial operations**
   - Shipping
   - Payments synchronization presentation
   - Notifications
   - Settings
2. **P3-RBAC-UI-B — order/returns workflows**
   - replace hard-coded order role checks with exact capabilities
   - return workflow mutation presentation
3. **P3-RBAC-UI-C — finance/payouts**
   - reconciliation
   - payout destination verification
   - payout review/process/retry/cancel/batching
   - require explicit financial confirmation/audit acceptance during implementation review

Each later package should remain path-budgeted and test view-only vs manage-capable principals. No package is activated by this document.