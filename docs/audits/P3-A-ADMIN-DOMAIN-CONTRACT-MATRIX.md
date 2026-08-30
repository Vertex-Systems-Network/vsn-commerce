# P3-A — Admin Domain Contract Matrix

Status: **IN PROGRESS — audit/planning only**

Canonical source base: `730d36f9c7970f4ac358e44e670b097061b51a45`
Working lane: `agent/20260830-vsn-commerce-abd-196`

## Guardrails

This artifact inventories and reconciles existing admin contracts only. P3-A does **not** authorize runtime behavior changes, schema changes, controller changes, React redesign, RBAC changes, P4/P5 activation, or destructive cleanup.

A row may be marked `VERIFIED` only after the React route, API route/controller/action and backend RBAC mapping are all matched from protected-main source. UI permission alone is not proof of backend authority.

## Phase 1 — React admin route inventory

Source: `resources/js/App.jsx`.

| Admin route | UI component | UI permission | Backend mapping | Audit state |
| --- | --- | --- | --- | --- |
| `/admin` | `AdminControl` | admin-area role gate | pending | UI_INVENTORIED |
| `/admin/users` | `AdminUsers` | `users.view` | pending | UI_INVENTORIED |
| `/admin/access` | `AdminAccess` | `users.view` | pending | UI_INVENTORIED |
| `/admin/vendors` | `AdminVendors` | `vendors.view` | pending | UI_INVENTORIED |
| `/admin/catalog` | `AdminCatalog` | `catalog.view` | pending | UI_INVENTORIED |
| `/admin/catalog/new` | `AdminProductEditor` | `catalog.manage` | pending | UI_INVENTORIED |
| `/admin/catalog/:id/edit` | `AdminProductEditor` | `catalog.manage` | pending | UI_INVENTORIED |
| `/admin/promotions` | `AdminPromotions` | `promotions.view` | pending | UI_INVENTORIED |
| `/admin/loyalty` | `AdminLoyalty` | `loyalty.view` | pending | UI_INVENTORIED |
| `/admin/games` | `AdminGames` | `games.view` | pending | UI_INVENTORIED |
| `/admin/tax` | `AdminTax` | `tax.view` | pending | UI_INVENTORIED |
| `/admin/reviews` | `AdminReviews` | `reviews.view` | pending | UI_INVENTORIED |
| `/admin/media` | `AdminMedia` | `media.view` | pending | UI_INVENTORIED |
| `/admin/compliance` | `AdminCompliance` | `compliance.view` | pending | UI_INVENTORIED |
| `/admin/risk` | `AdminRisk` | `risk.view` | pending | UI_INVENTORIED |
| `/admin/analytics` | `AdminAnalytics` | `analytics.view` | pending | UI_INVENTORIED |
| `/admin/orders` | `AdminOrders` | `orders.view` | pending | UI_INVENTORIED |
| `/admin/orders/:id` | `AdminOrderDetail` | `orders.view` | pending | UI_INVENTORIED |
| `/admin/shipping` | `AdminShipping` | `shipping.view` | pending | UI_INVENTORIED |
| `/admin/payments` | `AdminPayments` | `payments.view` | pending | UI_INVENTORIED |
| `/admin/returns` | `AdminReturns` | `returns.view` | pending | UI_INVENTORIED |
| `/admin/returns/:id` | `AdminReturnDetail` | `returns.view` | pending | UI_INVENTORIED |
| `/admin/finance` | `AdminFinanceCenter` | `finance.view` | pending | UI_INVENTORIED |
| `/admin/payouts` | `AdminPayouts` | `finance.view` | pending | UI_INVENTORIED |
| `/admin/notifications` | `AdminNotifications` | `notifications.view` | pending | UI_INVENTORIED |
| `/admin/settings` | `AdminSettings` | `settings.view` | pending | UI_INVENTORIED |
| `/admin/operations` | `OperationsCenter` | `operations.view` | pending | UI_INVENTORIED |
| `/admin/seller-quality` | `SellerQuality` | `vendors.view` | pending | UI_INVENTORIED |
| `/admin/production-readiness` | `ProductionReadiness` | `operations.view` | pending | UI_INVENTORIED |
| `/admin/acceptance` | `Acceptance` | `acceptance.view` | pending | UI_INVENTORIED |

Inventory result: **30 protected admin route surfaces** are explicitly represented by the current React router, including the `/admin` index.

## Required backend reconciliation

For every row above, P3-A must next record:

1. API method + `/api/admin/*` path(s);
2. controller class and action;
3. backend RBAC permission resolved by `Rbac::enforceAreaRequest()` / related enforcement;
4. CRUD completeness;
5. filter/search/pagination behavior;
6. loading, empty, error and retry states;
7. destructive/financial confirmation and idempotency semantics where applicable;
8. audit attribution where required;
9. existing PHPUnit/browser/source proof;
10. concrete gap classification and later bounded repair owner/path budget.

## Fail-closed audit rules

- Missing backend map => `UNMAPPED`, never inferred from the UI label.
- UI/backend permission contradiction => `CONTRADICTORY` and later repair must fail closed.
- Presentation-only settings => `DEAD_OR_PRESENTATION_ONLY_SETTING` until a runtime consumer is proven.
- Existing valid semantic coverage is referenced, not duplicated.
- P2 managed-media identity, stable references and non-destructive legacy compatibility remain immutable constraints for P3 planning.
- Oversized shared files are identified for later bounded splitting only when a repair touches their domain; P3-A itself does not refactor them.

## Next audit slice

Reconcile these 30 route surfaces against `routes/api.php`, controller actions and `app/Security/Rbac.php`, then group gaps into a dependency-ordered P3 repair sequence. No runtime repair begins before that sequence is accepted canonically.
