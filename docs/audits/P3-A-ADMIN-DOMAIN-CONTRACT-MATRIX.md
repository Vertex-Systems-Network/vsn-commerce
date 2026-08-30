# P3-A — Admin Domain Contract Matrix

Status: **SOURCE VERIFIED — FINAL ACCEPTANCE EVIDENCE PENDING**

Working lane: `agent/20260830-vsn-commerce-abd-196`

## Guardrails

This artifact reconciles existing admin contracts only. It does **not** authorize React, Laravel, RBAC, schema, controller, runtime or destructive changes. Backend deny-by-default authorization remains authoritative.

`CAPABILITY_VERIFIED` means the component-level read/mutation boundary was reviewed from source. A defect classification means the component was also reviewed and a concrete gap was found; it does not mean backend authorization is bypassed.

## Reconciled 30-route inventory

| Admin route | UI component | Route-entry permission | Backend / component result | Final audit state |
| --- | --- | --- | --- | --- |
| `/admin` | `AdminControl` | admin-area role gate | mandatory analytics read + synthetic green status | **C-P3A-004 / C-P3A-005** |
| `/admin/users` | `AdminUsers` | `users.view` | GET view; create/role writes require `users.manage` but presentation is ungated | **C-P3A-014 CAPABILITY_GAP** |
| `/admin/access` | `AdminAccess` | `users.view` | read-only `/admin/rbac`; no mutation surface | **CAPABILITY_VERIFIED** |
| `/admin/vendors` | `AdminVendors` | `vendors.view` | vendor writes require `vendors.manage`; seller-owner lookup also requires `users.view` | **C-P3A-015 / C-P3A-016** |
| `/admin/catalog` | `AdminCatalog` | `catalog.view` | review/category writes require `catalog.manage` but presentation is ungated | **C-P3A-017 CAPABILITY_GAP** |
| `/admin/catalog/new` | `AdminProductEditor` | `catalog.manage` | core catalog writes align; mandatory reusable-media read needs `media.view`, media-library management needs `media.manage` | **C-P3A-027 / C-P3A-028** |
| `/admin/catalog/:id/edit` | `AdminProductEditor` | `catalog.manage` | same cross-domain media composition as create route | **C-P3A-027 / C-P3A-028** |
| `/admin/promotions` | `AdminPromotions` | `promotions.view` | create/lifecycle writes require `promotions.manage` but presentation is ungated | **C-P3A-018 CAPABILITY_GAP** |
| `/admin/loyalty` | `AdminLoyalty` | `loyalty.view` | writes require `loyalty.manage` but presentation is ungated | **C-P3A-002 CAPABILITY_GAP** |
| `/admin/games` | `AdminGames` | `games.view` | writes require `games.manage` but presentation is ungated | **C-P3A-003 CAPABILITY_GAP** |
| `/admin/tax` | `AdminTax` | `tax.view` | jurisdiction/rate/profile writes require `tax.manage` but presentation is ungated | **C-P3A-019 CAPABILITY_GAP** |
| `/admin/reviews` | `AdminReviews` | `reviews.view` | moderation presentation uses role names rather than `reviews.moderate` | **C-P3A-020 ROLE_CAPABILITY_DRIFT** |
| `/admin/media` | `AdminMedia` | `media.view` | upload/archive writes require `media.manage` but presentation is ungated | **C-P3A-023 CAPABILITY_GAP** |
| `/admin/compliance` | `AdminCompliance` | `compliance.view` | review/security/audit secondary capabilities handled explicitly | **CAPABILITY_VERIFIED** |
| `/admin/risk` | `AdminRisk` | `risk.view` | holds/case writes require `risk.manage` but presentation is ungated | **C-P3A-022 CAPABILITY_GAP** |
| `/admin/analytics` | `AdminAnalytics` | `analytics.view` | export/schedule management requires `analytics.manage` but presentation is ungated | **C-P3A-021 CAPABILITY_GAP** |
| `/admin/orders` | `AdminOrders` | `orders.view` | list is read-only; server pagination metadata is not consumed | **C-P3A-029 PAGINATION_CONTRACT_GAP** |
| `/admin/orders/:id` | `AdminOrderDetail` | `orders.view` | operation/finance presentation uses role names instead of permission authority | **C-P3A-006 ROLE_CAPABILITY_DRIFT** |
| `/admin/shipping` | `AdminShipping` | `shipping.view` | retry/sync/cancel writes require `shipping.manage` but presentation is ungated | **C-P3A-007 CAPABILITY_GAP** |
| `/admin/payments` | `AdminPayments` | `payments.view` | provider sync requires `payments.manage` but presentation is ungated | **C-P3A-008 CAPABILITY_GAP** |
| `/admin/returns` | `AdminReturns` | `returns.view` | list is read-only; fixed server pagination is not consumed | **C-P3A-030 PAGINATION_CONTRACT_GAP** |
| `/admin/returns/:id` | `AdminReturnDetail` | `returns.view` | review/receive/refund/dispute mutations rendered without manage-capability presentation gate | **C-P3A-009 CAPABILITY_GAP / FINANCIAL_ACTION_PRESENTATION** |
| `/admin/finance` | `AdminFinanceCenter` | `finance.view` | reconciliation write requires `finance.manage` but presentation is ungated | **C-P3A-010 CAPABILITY_GAP** |
| `/admin/payouts` | `AdminPayouts` | `finance.view` | payout lifecycle writes require `finance.manage` but presentation is ungated | **C-P3A-011 CAPABILITY_GAP / FINANCIAL_ACTION_PRESENTATION** |
| `/admin/notifications` | `AdminNotifications` | `notifications.view` | broadcast/retry writes require `notifications.manage` but presentation is ungated | **C-P3A-012 CAPABILITY_GAP** |
| `/admin/settings` | `AdminSettings` | `settings.view` | save writes require `settings.manage` but presentation is ungated | **C-P3A-013 CAPABILITY_GAP** |
| `/admin/operations` | `OperationsCenter` | `operations.view` | mandatory finance reads + finance and incident mutations create cross-domain/management composition defects | **C-P3A-024 / C-P3A-025 / C-P3A-026** |
| `/admin/seller-quality` | `SellerQuality` | `vendors.view` | mandatory `/admin/shipping/quality` read requires `shipping.view` | **C-P3A-001 CONTRADICTORY_UI_BACKEND_PERMISSION** |
| `/admin/production-readiness` | `ProductionReadiness` | `operations.view` | launch/provider writes require `operations.manage` but presentation is ungated | **C-P3A-003 CAPABILITY_GAP** |
| `/admin/acceptance` | `Acceptance` | `acceptance.view` | manage/sign/seal capabilities handled separately | **CAPABILITY_VERIFIED** |

Inventory result: **30 / 30 protected admin route surfaces are component-level source verified. No `BACKEND_MAPPED` or component-pending row remains.**

## Finding index

### Route / cross-domain authority composition

- **C-P3A-001** — Seller Quality `vendors.view` route requires `shipping.view` data.
- **C-P3A-004** — admin index role gate does not guarantee mandatory `analytics.view`.
- **C-P3A-016** — Vendors surface requires `users.view` seller-owner lookup in addition to `vendors.view`.
- **C-P3A-024** — Operations Center `operations.view` surface composes mandatory finance-domain reads.
- **C-P3A-027** — catalog editor `catalog.manage` surface unconditionally loads reusable media requiring `media.view`.

### View/manage presentation gaps

- **C-P3A-002** — Loyalty.
- **C-P3A-003** — Games / Production Readiness.
- **C-P3A-007** — Shipping.
- **C-P3A-008** — Payments.
- **C-P3A-009** — Return detail.
- **C-P3A-010** — Finance reconciliation.
- **C-P3A-011** — Payout lifecycle.
- **C-P3A-012** — Notifications.
- **C-P3A-013** — Settings.
- **C-P3A-014** — Users.
- **C-P3A-015** — Vendors.
- **C-P3A-017** — Catalog index.
- **C-P3A-018** — Promotions.
- **C-P3A-019** — Tax.
- **C-P3A-021** — Analytics.
- **C-P3A-022** — Risk.
- **C-P3A-023** — Media.
- **C-P3A-025** — Operations Center finance/payout presentation.
- **C-P3A-026** — Operations Center incident command presentation.
- **C-P3A-028** — catalog editor reusable media management presentation.

### Role-policy drift

- **C-P3A-006** — Order detail hard-coded role presentation.
- **C-P3A-020** — Review moderation hard-coded role presentation.

### Truthfulness / list completeness

- **C-P3A-005** — unsupported synthetic green subsystem status.
- **C-P3A-029** — orders list drops server pagination contract.
- **C-P3A-030** — returns list drops server pagination contract.

## Positive controls

- `AdminCompliance` demonstrates explicit secondary capability checks.
- `Acceptance` demonstrates view/manage/sign/seal separation.
- `AdminAccess` is a genuinely read-only permission-inspection surface.
- `AdminOrders` and `AdminReturns` demonstrate list-level read-only presentation; their defects are pagination/list completeness, not hidden mutations.

These local patterns should be reused. No new client authorization framework is justified.

## Fail-closed interpretation

None of the client-side presentation defects above is evidence to weaken server RBAC. Later repair must keep server authorization authoritative, preserve idempotency/audit/confirmation on consequential writes, and test both view-only and manage-capable principals.

## Remaining acceptance gate

The route matrix itself is now reconciled. P3-A remains unaccepted until the companion acceptance ledger records bounded later repair path budgets, finding-ID integrity, final exact-head CI/CodeQL/Governance Code Scanning, review provenance and audit-only promotion/merge evidence.