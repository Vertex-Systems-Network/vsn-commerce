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
- `resources/js/pages/Systems.jsx` / `SystemsServer.jsx` — admin index and seller-quality contracts
- `resources/js/pages/Production.jsx` — production-readiness capability audit
- `resources/js/pages/Acceptance.jsx` — acceptance multi-permission audit
- `resources/js/pages/AdminOperations.jsx` — orders, shipping, payments, returns, finance, payouts, notifications and settings capability audit
- `docs/audits/P3-A-ADMIN-CONTROL-AUDIT.md` — `/admin` index + operational-status findings
- `docs/audits/P3-A-ADMIN-OPERATIONS-CAPABILITY-AUDIT.md` — operations-domain capability drift findings

## Guardrails

This artifact inventories and reconciles existing admin contracts only. P3-A does **not** authorize runtime behavior changes, schema changes, controller changes, React redesign, RBAC changes, P4/P5 activation, or destructive cleanup.

A row may be marked `BACKEND_MAPPED` after the React route, API domain and backend RBAC mapping are matched from protected-main source. It is marked `CAPABILITY_VERIFIED` only after the component is also checked for read-vs-mutation permission handling. UI permission alone is never proof of backend authority.

## Phase 1 — React admin route inventory

| Admin route | UI component | Route-entry permission | Backend authority | Audit state |
| --- | --- | --- | --- | --- |
| `/admin` | `AdminControl` | admin-area role gate | mandatory GET `/admin/analytics` → `analytics.view` | **CONTRADICTORY_UI_BACKEND_PERMISSION** |
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
| `/admin/orders/:id` | `AdminOrderDetail` | `orders.view` | GET `orders.view`; order writes `orders.manage`; finance action separate | **ROLE_CAPABILITY_DRIFT** |
| `/admin/shipping` | `AdminShipping` | `shipping.view` | GET `shipping.view`; writes `shipping.manage` | **CAPABILITY_GAP** |
| `/admin/payments` | `AdminPayments` | `payments.view` | GET `payments.view`; writes `payments.manage` | **CAPABILITY_GAP** |
| `/admin/returns` | `AdminReturns` | `returns.view` | GET `returns.view`; writes `returns.manage` | BACKEND_MAPPED |
| `/admin/returns/:id` | `AdminReturnDetail` | `returns.view` | GET `returns.view`; workflow/financial writes require protected mutation authority | **CAPABILITY_GAP** |
| `/admin/finance` | `AdminFinanceCenter` | `finance.view` | GET `finance.view`; reconcile write `finance.manage` | **CAPABILITY_GAP** |
| `/admin/payouts` | `AdminPayouts` | `finance.view` | GET `finance.view`; payout lifecycle writes `finance.manage` | **CAPABILITY_GAP** |
| `/admin/notifications` | `AdminNotifications` | `notifications.view` | GET `notifications.view`; writes `notifications.manage` | **CAPABILITY_GAP** |
| `/admin/settings` | `AdminSettings` | `settings.view` | GET `settings.view`; writes `settings.manage` | **CAPABILITY_GAP** |
| `/admin/operations` | `OperationsCenter` | `operations.view` | GET `operations.view`; writes `operations.manage` | BACKEND_MAPPED_COMPONENT_PENDING |
| `/admin/seller-quality` | `SellerQuality` | `vendors.view` | component GET `/admin/shipping/quality` → `shipping.view` | **CONTRADICTORY_UI_BACKEND_PERMISSION** |
| `/admin/production-readiness` | `ProductionReadiness` | `operations.view` | GET `operations.view`; launch/provider writes `operations.manage` | **CAPABILITY_GAP** |
| `/admin/acceptance` | `Acceptance` | `acceptance.view` | manage/sign/seal permissions separated | **CAPABILITY_VERIFIED** |

Inventory result: **30 protected admin route surfaces** are explicitly represented by the current React router, including the `/admin` index.

## Phase 2 — source-verified authorization findings

### C-P3A-001 — Seller Quality route permission contract is contradictory

React route `/admin/seller-quality` requires `vendors.view`, while the component loads `/admin/shipping/quality` and backend deny-by-default RBAC requires `shipping.view` for that GET surface.

Classification: `CONTRADICTORY_UI_BACKEND_PERMISSION`.

Later repair must align route-entry capability with the server contract or deliberately expose a vendor-quality contract; it must not weaken backend enforcement merely to make the page load.

### C-P3A-002 — Loyalty and Games expose mutation controls to view-only principals

Both routes enter on `*.view`; backend writes require `*.manage`; mutation controls are rendered without matching manage-capability presentation checks.

Classification: `CAPABILITY_GAP`.

### C-P3A-003 — Production Readiness does not model the operations view/manage split

The component reads under `operations.view` but renders launch-gate/provider mutation actions whose backend authority is `operations.manage`.

Classification: `CAPABILITY_GAP`.

### C-P3A-004 / 005 — Admin index capability contradiction and unverified health assertions

`AdminControl` is reachable through the admin-area role gate but its mandatory data request is `/admin/analytics`, requiring `analytics.view`. It also renders five subsystem statuses as successful without fetching or deriving authoritative health/readiness evidence.

Classifications:

- `C-P3A-004`: `CONTRADICTORY_UI_BACKEND_PERMISSION`
- `C-P3A-005`: `UNVERIFIED_OPERATIONAL_ASSERTION`

Detailed evidence: `docs/audits/P3-A-ADMIN-CONTROL-AUDIT.md`.

### C-P3A-006 — Order detail uses role names instead of permission authority

`AdminOrderDetail` derives operational and finance presentation from hard-coded role strings while server authorization is permission-based.

Classification: `ROLE_CAPABILITY_DRIFT`.

### C-P3A-007 through C-P3A-013 — repeated operations-domain view/manage drift

Source review of `AdminOperations.jsx` confirms mutation controls are presented without the corresponding manage-capability check in these route domains:

- Shipping (`C-P3A-007`)
- Payments (`C-P3A-008`)
- Return detail (`C-P3A-009`)
- Finance reconciliation (`C-P3A-010`)
- Payout lifecycle (`C-P3A-011`)
- Notifications (`C-P3A-012`)
- Settings (`C-P3A-013`)

The payouts and returns findings include financially consequential actions and therefore require stronger confirmation/audit/idempotency acceptance during any later implementation package.

Detailed evidence: `docs/audits/P3-A-ADMIN-OPERATIONS-CAPABILITY-AUDIT.md`.

### Positive control — Compliance and Acceptance already model secondary capabilities correctly

`AdminCompliance` enters on `compliance.view` but conditionally loads/renders review, security-event and audit-log capabilities using `compliance.review`, `security.events.view` and `audit.view`.

`Acceptance` similarly separates `acceptance.view` from `acceptance.manage`, `acceptance.sign` and `acceptance.seal` before rendering privileged actions.

These implementations are the preferred local pattern for later P3 repairs; a new permission framework is not justified by current evidence.

## Current cross-route pattern

The audit now has source evidence for a repeated client-side anti-pattern:

`route *.view` → component renders read + protected write controls → backend rejects unauthorized writes with `*.manage`.

This is **not** evidence of a backend RBAC bypass. It is a UI capability-model correctness defect and an avoidable source of broken view-only workflows. Backend fail-closed behavior must remain authoritative.

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
- Role-name presentation that disagrees with permission authority => `ROLE_CAPABILITY_DRIFT`.
- Presentation-only settings => `DEAD_OR_PRESENTATION_ONLY_SETTING` until a runtime consumer is proven.
- Existing valid semantic coverage is referenced, not duplicated.
- P2 managed-media identity, stable references and non-destructive legacy compatibility remain immutable constraints for P3 planning.
- Oversized shared files are identified for later bounded splitting only when a repair touches their domain; P3-A itself does not refactor them.

## Provisional repair sequence — planning only

This is not implementation authorization.

1. **R1 — route/API permission-contract alignment**
   - resolve Seller Quality and `/admin` index contradictions without weakening backend fail-closed enforcement.
2. **R2 — capability-aware mutation presentation**
   - reuse the Compliance/Acceptance `hasPermission(...)` pattern for view/manage splits.
   - eliminate hard-coded role presentation in order detail where permission authority exists.
3. **R3 — financial-operation presentation hardening**
   - returns, finance and payouts receive explicit capability, confirmation, audit and idempotency acceptance before implementation.
4. **R4 — operational-observability correctness**
   - replace unsupported green-health assertions with authoritative health/readiness evidence or non-status informational presentation.
5. **R5 — CRUD/state completeness**
   - rank missing workflows, loading/error/empty/retry behavior and domain-state gaps only after authorization consistency is mapped.
6. **R6 — bounded implementation packages**
   - create dependency-ordered, path-budgeted P3 repair packages only after the full P3-A matrix is canonically accepted.

No runtime repair begins from this document alone.
