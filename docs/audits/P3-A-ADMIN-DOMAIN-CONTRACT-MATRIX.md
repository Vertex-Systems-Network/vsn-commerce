# P3-A — Admin Domain Contract Matrix

Status: **IN PROGRESS — audit/planning only**

Canonical source base: `730d36f9c7970f4ac358e44e670b097061b51a45`
Working lane: `agent/20260830-vsn-commerce-abd-196`

Source evidence reviewed in this slice:

- `resources/js/App.jsx` — protected admin route + UI permission inventory
- `routes/api.php` — current Laravel API/controller routing
- `app/Security/Rbac.php` — backend deny-by-default permission mapping
- `resources/js/pages/AdminCompliance.jsx` — capability-aware reference implementation
- `resources/js/pages/AdminEngagement.jsx` — loyalty/games mutation capability audit
- `resources/js/pages/SystemsServer.jsx` — seller-quality server contract audit
- `resources/js/pages/Production.jsx` — production-readiness capability audit
- `resources/js/pages/Acceptance.jsx` — acceptance multi-permission audit

## Guardrails

This artifact inventories and reconciles existing admin contracts only. P3-A does **not** authorize runtime behavior changes, schema changes, controller changes, React redesign, RBAC changes, P4/P5 activation, or destructive cleanup.

A row may be marked `BACKEND_MAPPED` after the React route, API domain and backend RBAC mapping are matched from protected-main source. It is marked `CAPABILITY_VERIFIED` only after the component is also checked for read-vs-mutation permission handling. UI permission alone is never proof of backend authority.

## Phase 1 — React admin route inventory

| Admin route | UI component | Route-entry permission | Backend authority | Audit state |
| --- | --- | --- | --- | --- |
| `/admin` | `AdminControl` | admin-area role gate | multi-domain; component audit pending | UI_INVENTORIED |
| `/admin/users` | `AdminUsers` | `users.view` | GET `users.view`; writes `users.manage` | BACKEND_MAPPED |
| `/admin/access` | `AdminAccess` | `users.view` | `/admin/rbac` → `users.view` | BACKEND_MAPPED |
| `/admin/vendors` | `AdminVendors` | `vendors.view` | GET `vendors.view`; writes `vendors.manage` | BACKEND_MAPPED |
| `/admin/catalog` | `AdminCatalog` | `catalog.view` | GET `catalog.view`; writes `catalog.manage` | BACKEND_MAPPED |
| `/admin/catalog/new` | `AdminProductEditor` | `catalog.manage` | product writes `catalog.manage` | BACKEND_MAPPED |
| `/admin/catalog/:id/edit` | `AdminProductEditor` | `catalog.manage` | product writes `catalog.manage` | BACKEND_MAPPED |
| `/admin/promotions` | `AdminPromotions` | `promotions.view` | GET `promotions.view`; writes `promotions.manage` | BACKEND_MAPPED |
| `/admin/loyalty` | `AdminLoyalty` | `loyalty.view` | reads `loyalty.view`; writes `loyalty.manage` | **CAPABILITY_GAP** |
| `/admin/games` | `AdminGames` | `games.view` | reads `games.view`; writes `games.manage` | **CAPABILITY_GAP** |
| `/admin/tax` | `AdminTax` | `tax.view` | GET `tax.view`; writes `tax.manage` | BACKEND_MAPPED |
| `/admin/reviews` | `AdminReviews` | `reviews.view` | GET `reviews.view`; writes `reviews.moderate` | BACKEND_MAPPED |
| `/admin/media` | `AdminMedia` | `media.view` | GET `media.view`; writes `media.manage` | BACKEND_MAPPED |
| `/admin/compliance` | `AdminCompliance` | `compliance.view` | base `compliance.view`; review `compliance.review`; security/audit separate | **CAPABILITY_VERIFIED** |
| `/admin/risk` | `AdminRisk` | `risk.view` | GET `risk.view`; writes `risk.manage` | BACKEND_MAPPED |
| `/admin/analytics` | `AdminAnalytics` | `analytics.view` | GET `analytics.view`; writes `analytics.manage` | BACKEND_MAPPED |
| `/admin/orders` | `AdminOrders` | `orders.view` | GET `orders.view`; writes `orders.manage` | BACKEND_MAPPED |
| `/admin/orders/:id` | `AdminOrderDetail` | `orders.view` | GET `orders.view`; writes `orders.manage` | BACKEND_MAPPED |
| `/admin/shipping` | `AdminShipping` | `shipping.view` | GET `shipping.view`; writes `shipping.manage` | BACKEND_MAPPED |
| `/admin/payments` | `AdminPayments` | `payments.view` | GET `payments.view`; writes `payments.manage` | BACKEND_MAPPED |
| `/admin/returns` | `AdminReturns` | `returns.view` | GET `returns.view`; writes `returns.manage` | BACKEND_MAPPED |
| `/admin/returns/:id` | `AdminReturnDetail` | `returns.view` | GET `returns.view`; writes `returns.manage` | BACKEND_MAPPED |
| `/admin/finance` | `AdminFinanceCenter` | `finance.view` | GET `finance.view`; writes `finance.manage` | BACKEND_MAPPED |
| `/admin/payouts` | `AdminPayouts` | `finance.view` | GET `finance.view`; writes `finance.manage` | BACKEND_MAPPED |
| `/admin/notifications` | `AdminNotifications` | `notifications.view` | GET `notifications.view`; writes `notifications.manage` | BACKEND_MAPPED |
| `/admin/settings` | `AdminSettings` | `settings.view` | GET `settings.view`; writes `settings.manage` | BACKEND_MAPPED |
| `/admin/operations` | `OperationsCenter` | `operations.view` | GET `operations.view`; writes `operations.manage` | BACKEND_MAPPED_COMPONENT_PENDING |
| `/admin/seller-quality` | `SellerQuality` | `vendors.view` | component GET `/admin/shipping/quality` → `shipping.view` | **CONTRADICTORY_UI_BACKEND_PERMISSION** |
| `/admin/production-readiness` | `ProductionReadiness` | `operations.view` | GET `operations.view`; launch/provider writes `operations.manage` | **CAPABILITY_GAP** |
| `/admin/acceptance` | `Acceptance` | `acceptance.view` | manage/sign/seal permissions separated | **CAPABILITY_VERIFIED** |

Inventory result: **30 protected admin route surfaces** are explicitly represented by the current React router, including the `/admin` index.

## Phase 2 — source-verified authorization findings

### C-P3A-001 — Seller Quality route permission contract is contradictory

**Evidence chain**

1. React route `/admin/seller-quality` requires `vendors.view`.
2. `SellerQuality()` loads `/admin/shipping/quality`.
3. Backend `Rbac::adminPermission()` maps GET `/api/v1/admin/shipping/*` to `shipping.view`.
4. Backend enforcement is deny-by-default when the permission is absent.

**Impact**

A principal can be allowed through the route guard with `vendors.view` yet receive HTTP 403 from the page's only data API if it lacks `shipping.view`. Backend security remains fail-closed, but the UI/API capability contract is inconsistent and produces an avoidable broken admin route.

**Classification:** `CONTRADICTORY_UI_BACKEND_PERMISSION`

**Later bounded repair candidate:** align route-entry capability with the server contract or deliberately expose a vendor-quality API under a vendor-quality permission. Do not weaken backend enforcement merely to make the page load.

### C-P3A-002 — Loyalty and Games expose mutation controls to view-only principals

**Evidence chain**

1. `/admin/loyalty` enters on `loyalty.view`; `/admin/games` enters on `games.view`.
2. Backend maps GETs to `*.view` and mutation requests to `*.manage`.
3. `AdminLoyalty` renders wallet adjustment, expiry and affiliate mutation actions without checking `loyalty.manage`.
4. `AdminGames` renders create/close/draw/cancel/refund/fulfill mutation actions without checking `games.manage`.

**Impact**

Backend authorization still rejects unauthorized writes, so this is not an RBAC bypass. It is a capability-model/UI correctness defect: view-only users are shown controls they cannot execute and discover authorization only after a failed request.

**Classification:** `CAPABILITY_GAP`

**Later bounded repair candidate:** retain read-only access but render/enable mutation controls only for the matching `*.manage` permission; add route/component tests for view-only and manage-capable principals.

### C-P3A-003 — Production Readiness has the same view/manage split but does not model it in the component

**Evidence chain**

1. `/admin/production-readiness` enters on `operations.view`.
2. The component reads launch-gate/provider endpoints covered by `operations.view`.
3. It also renders `Run launch gate`, `Probe providers`, and provider `Reconcile` actions unconditionally.
4. Backend maps those POSTs to `operations.manage`.

**Classification:** `CAPABILITY_GAP`

**Later bounded repair candidate:** use the same capability-aware pattern as Compliance/Acceptance for `operations.manage` mutation controls.

### Positive control — Compliance and Acceptance already model secondary capabilities correctly

`AdminCompliance` enters on `compliance.view` but conditionally loads/renders review, security-event and audit-log capabilities using `compliance.review`, `security.events.view` and `audit.view`.

`Acceptance` similarly separates `acceptance.view` from `acceptance.manage`, `acceptance.sign` and `acceptance.seal` before rendering privileged actions.

These implementations are the preferred local pattern for later P3 repairs; a new permission framework is not justified by current evidence.

## Remaining P3-A audit requirements

For every route/domain not yet marked `CAPABILITY_VERIFIED`, continue recording:

1. exact API methods and paths used by the component;
2. controller class and action;
3. backend RBAC permission;
4. read vs mutation capability handling in the UI;
5. CRUD completeness;
6. filter/search/pagination behavior;
7. loading, empty, error and retry states;
8. destructive/financial confirmation and idempotency semantics where applicable;
9. audit attribution where required;
10. existing PHPUnit/browser/source proof;
11. concrete gap classification and later bounded repair owner/path budget.

## Fail-closed audit rules

- Missing backend map => `UNMAPPED`, never inferred from the UI label.
- UI/backend permission contradiction => `CONTRADICTORY_UI_BACKEND_PERMISSION`; repair must not weaken backend RBAC.
- Route enters on `*.view` while privileged controls require another permission => `CAPABILITY_GAP` until the component models it explicitly.
- Presentation-only settings => `DEAD_OR_PRESENTATION_ONLY_SETTING` until a runtime consumer is proven.
- Existing valid semantic coverage is referenced, not duplicated.
- P2 managed-media identity, stable references and non-destructive legacy compatibility remain immutable constraints for P3 planning.
- Oversized shared files are identified for later bounded splitting only when a repair touches their domain; P3-A itself does not refactor them.

## Provisional repair sequence — planning only

This is not implementation authorization.

1. **R1 — permission-contract alignment:** resolve `C-P3A-001` without weakening backend fail-closed enforcement.
2. **R2 — capability-aware mutation rendering:** apply the proven Compliance/Acceptance pattern to `C-P3A-002` and `C-P3A-003`, then audit the remaining admin components for the same class.
3. **R3 — CRUD/state completeness:** only after authorization consistency is mapped, rank domain gaps for missing workflows, loading/error/empty/retry behavior, destructive confirmations and idempotency.
4. **R4 — bounded implementation packages:** create path-budgeted, dependency-ordered P3 repair packages only after the full P3-A matrix is canonically accepted.

No runtime repair begins from this document alone.
