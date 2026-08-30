# P3-A — Admin Audit Acceptance Ledger

Status: **SOURCE VERIFIED — FINAL CI / REVIEW / PROMOTION EVIDENCE PENDING**

Repository: `Vertex-Systems-Network/vsn-commerce`  
Lane: `agent/20260830-vsn-commerce-abd-196`  
Parent: `docs/audits/P3-A-ADMIN-DOMAIN-CONTRACT-MATRIX.md`

## Authority boundary

This is the canonical P3-A finding registry and later-repair plan. It does **not** authorize React, Laravel, RBAC, schema, controller or runtime changes. Backend deny-by-default authorization remains authoritative.

## Canonical source-verified findings

| ID | Domain | Classification | Core planning consequence |
| --- | --- | --- | --- |
| C-P3A-001 | Seller Quality | `CONTRADICTORY_UI_BACKEND_PERMISSION` | `vendors.view` route depends on `shipping.view` data |
| C-P3A-002 | Loyalty | `CAPABILITY_GAP` | view surface exposes `loyalty.manage` writes |
| C-P3A-003 | Games / Production Readiness | `CAPABILITY_GAP` | view surfaces expose protected manage actions |
| C-P3A-004 | Admin index | `CONTRADICTORY_UI_BACKEND_PERMISSION` | route gate does not guarantee mandatory `analytics.view` |
| C-P3A-005 | Admin index | `UNVERIFIED_OPERATIONAL_ASSERTION` | synthetic green subsystem status without health evidence |
| C-P3A-006 | Order detail | `ROLE_CAPABILITY_DRIFT` | hard-coded roles substitute for permission authority |
| C-P3A-007 | Shipping | `CAPABILITY_GAP` | `shipping.view` exposes `shipping.manage` actions |
| C-P3A-008 | Payments | `CAPABILITY_GAP` | `payments.view` exposes provider sync/manage action |
| C-P3A-009 | Return detail | `CAPABILITY_GAP / FINANCIAL_ACTION_PRESENTATION` | view route exposes workflow/refund mutations |
| C-P3A-010 | Finance | `CAPABILITY_GAP` | `finance.view` exposes reconciliation write |
| C-P3A-011 | Payouts | `CAPABILITY_GAP / FINANCIAL_ACTION_PRESENTATION` | view route exposes payout lifecycle mutations |
| C-P3A-012 | Notifications | `CAPABILITY_GAP` | view route exposes broadcast/retry writes |
| C-P3A-013 | Settings | `CAPABILITY_GAP` | view route exposes settings writes |
| C-P3A-014 | Users | `CAPABILITY_GAP` | `users.view` exposes create/role mutation |
| C-P3A-015 | Vendors | `CAPABILITY_GAP` | `vendors.view` exposes create/status mutation |
| C-P3A-016 | Vendors | `CONTRADICTORY_UI_BACKEND_PERMISSION / CROSS_DOMAIN_READ_DEPENDENCY` | seller-owner lookup also requires `users.view` |
| C-P3A-017 | Catalog index | `CAPABILITY_GAP` | `catalog.view` exposes review/category writes |
| C-P3A-018 | Promotions | `CAPABILITY_GAP` | `promotions.view` exposes create/lifecycle writes |
| C-P3A-019 | Tax | `CAPABILITY_GAP` | `tax.view` exposes jurisdiction/rate/profile writes |
| C-P3A-020 | Reviews | `ROLE_CAPABILITY_DRIFT` | role list substitutes for `reviews.moderate` |
| C-P3A-021 | Analytics | `CAPABILITY_GAP` | `analytics.view` exposes export/schedule management |
| C-P3A-022 | Risk | `CAPABILITY_GAP` | `risk.view` exposes holds/case mutations |
| C-P3A-023 | Media | `CAPABILITY_GAP` | `media.view` exposes upload/archive writes |
| C-P3A-024 | Operations Center | `CONTRADICTORY_UI_BACKEND_PERMISSION / CROSS_DOMAIN_READ_DEPENDENCY` | `operations.view` mandatory load composes finance reads |
| C-P3A-025 | Operations Center | `CAPABILITY_GAP / FINANCIAL_ACTION_PRESENTATION` | finance/payout mutations lack `finance.manage` presentation gate |
| C-P3A-026 | Operations Center | `CAPABILITY_GAP / INCIDENT_COMMAND_PRESENTATION` | incident mutations lack `operations.manage` presentation gate |
| C-P3A-027 | Catalog editor | `CONTRADICTORY_UI_BACKEND_PERMISSION / CROSS_DOMAIN_READ_DEPENDENCY` | `catalog.manage` editor unconditionally loads reusable media requiring `media.view` |
| C-P3A-028 | Catalog editor | `CAPABILITY_GAP / CROSS_DOMAIN_MEDIA_MANAGEMENT_PRESENTATION` | media-library upload/archive require `media.manage` but are rendered inside catalog editor without that boundary |
| C-P3A-029 | Orders list | `PAGINATION_CONTRACT_GAP / LIST_COMPLETENESS` | server paginates but UI exposes only first page |
| C-P3A-030 | Returns list | `PAGINATION_CONTRACT_GAP / LIST_COMPLETENESS` | server paginates but UI exposes only first page |

Finding registry rule: **C-P3A-001 through C-P3A-030 are contiguous and canonical here.** Supporting audit files may explain the same ID but must not redefine its classification or authority consequence.

## Positive controls retained

- `AdminCompliance` separates base view permission from review/security/audit capabilities.
- `Acceptance` separates `acceptance.view`, `acceptance.manage`, `acceptance.sign` and `acceptance.seal`.
- `AdminAccess` is read-only under `users.view`.
- `AdminOrders` and `AdminReturns` are list-level read-only surfaces; their defects are pagination completeness, not hidden mutation presentation.
- Operations Center uses server-returned operational state; later permission repair must preserve that truthfulness pattern.

## Systemic defect classes

### A — route/API authority composition

Known instances: `/admin`, Seller Quality, Vendors seller-owner lookup, Operations Center finance composition, and Catalog Editor reusable-media composition. Repair the route/data contract without weakening backend RBAC.

### B — view/manage presentation drift

Repeated pattern: `*.view` route → read succeeds → protected mutation controls render → backend correctly requires `*.manage`. Later UI repair must use existing `hasPermission(...)`; backend policy is not broadened to accommodate presentation drift.

### C — role-name authority drift

Order detail and review moderation duplicate permission policy through role-name lists. Replace presentation decisions with permission checks; server authority remains unchanged unless separate backend evidence requires change.

### D — financial/security-sensitive presentation

Returns, finance, payouts, tax, risk and operations incident/financial actions require explicit negative authorization coverage, confirmation where appropriate, idempotency and audit attribution in later packages.

### E — observability truthfulness

AdminControl synthetic green state is not authoritative health evidence. Unknown/degraded/error must remain representable.

### F — list/state completeness

Orders and Returns drop server pagination. Loading/retry/error-state cleanup remains secondary to authorization repair and belongs to a separate bounded package.

## Later repair packages and exact path budgets — NOT ACTIVATED

The paths below are maximum intended source budgets for each later package. A package must be created from fresh accepted `main`, re-read source, and reduce scope further if possible. Adding unrelated paths requires a new planning decision.

### P3-R1A — admin-index / seller-quality route-data composition

Owner: **Admin UI routing + operational read contract**.

Allowed source paths:

1. `resources/js/App.jsx`
2. `resources/js/pages/Systems.jsx`
3. `resources/js/pages/SystemsServer.jsx`
4. one focused browser/source contract test under `tests/Feature/`

Findings: C-P3A-001, C-P3A-004.

### P3-R1B — vendor owner lookup composition

Owner: **Admin Vendors UI contract**.

Allowed source paths:

1. `resources/js/App.jsx` only if route entry must explicitly compose capability;
2. `resources/js/pages/AdminVendors.jsx`
3. one focused authorization/source contract test under `tests/Feature/`

Finding: C-P3A-016. C-P3A-015 may be repaired in the same branch only if the final branch still remains inside this three-path budget and has separate view/manage negative coverage; otherwise use P3-R2A.

### P3-R1C — catalog editor / reusable-media composition

Owner: **Catalog UI + reusable media presentation boundary**.

Allowed source paths:

1. `resources/js/App.jsx` only if editor entry capability composition is deliberately changed;
2. `resources/js/pages/CatalogManagement.jsx`
3. `resources/js/components/MediaLibraryPanel.jsx`
4. `tests/Feature/CatalogMediaWriteContractTest.php` or one replacement focused contract test

Findings: C-P3A-027, C-P3A-028.

Product-owned `/admin/products/{product}/media...` authority must remain catalog-owned unless a separate backend change is explicitly reviewed.

### P3-R1D — Operations Center cross-domain composition

Owner: **Operations UI capability composition**.

Allowed source paths:

1. `resources/js/App.jsx` only if route-entry composition changes;
2. `resources/js/pages/Systems.jsx`
3. `resources/js/pages/SystemsServer.jsx`
4. `tests/Feature/AdminOperationalPanelTest.php`

Findings: C-P3A-024, C-P3A-025, C-P3A-026.

Do not weaken finance or operations backend permissions.

### P3-R2A — core non-financial view/manage presentation

Owner: **Admin UI capability presentation**.

Split into sub-PRs of at most **two page source files + one focused test file**. Eligible source files/findings:

- `resources/js/pages/AdminUsers.jsx` — C-P3A-014
- `resources/js/pages/AdminVendors.jsx` — C-P3A-015
- `resources/js/pages/CatalogManagement.jsx` — C-P3A-017
- `resources/js/pages/Promotions.jsx` — C-P3A-018
- `resources/js/pages/AdminEngagement.jsx` — C-P3A-002, C-P3A-003 games portion
- `resources/js/pages/AdminMedia.jsx` / `resources/js/components/MediaLibraryPanel.jsx` — C-P3A-023
- `resources/js/pages/AdminOperations.jsx` — C-P3A-012, C-P3A-013
- `resources/js/pages/Production.jsx` — C-P3A-003 production-readiness portion

Every sub-PR must test a view-only principal and the corresponding manage-capable principal.

### P3-R3 — role-to-permission normalization

Owner: **Admin UI permission model**.

Allowed source paths:

1. `resources/js/pages/AdminOperations.jsx` — C-P3A-006
2. `resources/js/pages/AdminReviews.jsx` — C-P3A-020
3. one focused browser/source contract test under `tests/Feature/`

No new client authorization framework; reuse `useAuth().hasPermission(...)`.

### P3-R4A — returns / finance / payouts / payment-shipping consequential actions

Owner: **Admin financial/operations presentation**.

Allowed source paths:

1. `resources/js/pages/AdminOperations.jsx`
2. one focused feature/source contract test under `tests/Feature/`

Findings: C-P3A-007, C-P3A-008, C-P3A-009, C-P3A-010, C-P3A-011.

Server idempotency, refund/payout authority and audit behavior are retained. Backend files are read-only dependencies unless a separate server defect is proven.

### P3-R4B — tax / analytics / risk consequential actions

Owner: **Admin specialized capability presentation**.

Use separate sub-PRs, each limited to **one page source file + one focused test file**:

- `resources/js/pages/Tax.jsx` — C-P3A-019
- `resources/js/pages/AdminAnalytics.jsx` — C-P3A-021
- `resources/js/pages/Risk.jsx` — C-P3A-022

### P3-R5 — operational truthfulness

Owner: **Admin operational observability**.

Allowed source paths:

1. `resources/js/pages/Systems.jsx`
2. `resources/js/pages/SystemsServer.jsx` only if server-derived display mapping is required
3. `tests/Feature/AdminOperationalPanelTest.php`

Finding: C-P3A-005.

Never replace synthetic green with another inferred success signal.

### P3-R6 — pagination / loading / retry completeness

Owner: **Admin list UX contract**.

Allowed source paths:

1. `resources/js/pages/AdminOperations.jsx`
2. one focused browser/source contract test under `tests/Feature/`

Findings: C-P3A-029, C-P3A-030 plus loading/error retry cleanup proven in the same list components. No return-detail mutation changes in this package.

## Acceptance integrity checklist

At this checkpoint:

- 30 / 30 admin route surfaces are component-level verified in the parent matrix;
- remaining route queue is zero;
- canonical finding IDs are C-P3A-001..030 with no numeric gaps;
- supporting audits retain the same classification/authority meaning as this registry;
- later repair ownership/path budgets are recorded above;
- runtime repair remains unauthorized.

Still required before P3-A acceptance/merge:

1. final exact-head CI PASS;
2. final exact-head CodeQL PASS;
3. final exact-head Governance Code Scanning PASS;
4. clean review threads/comments and SELF REVIEW or independent review provenance;
5. audit-only promotion/merge;
6. post-merge re-read of `main` confirming only audit documentation landed;
7. fresh later implementation branches for P3-R* packages.

## Current decision

**P3-A source audit is complete, but merge acceptance is still pending final exact-head CI/security/review evidence.**

Do not start runtime repair from this branch.