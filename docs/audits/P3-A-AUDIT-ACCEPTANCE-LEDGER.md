# P3-A — Admin Audit Acceptance Ledger

Status: **IN PROGRESS — PLANNING / NO REPAIR AUTHORITY**

Repository: `Vertex-Systems-Network/vsn-commerce`  
Lane: `agent/20260830-vsn-commerce-abd-196`  
Parent: `docs/audits/P3-A-ADMIN-DOMAIN-CONTRACT-MATRIX.md`

## Purpose

This ledger consolidates the source-verified P3-A admin authorization/presentation findings into one dependency-ordered planning boundary. It does not authorize React, Laravel, RBAC, schema, controller or runtime changes.

Backend deny-by-default authorization remains authoritative. P3-A defects identified here are route/component contract defects unless a separate finding proves a server-side bypass.

## Source-verified findings

| ID | Domain | Classification | Core planning consequence |
| --- | --- | --- | --- |
| C-P3A-001 | Seller Quality | `CONTRADICTORY_UI_BACKEND_PERMISSION` | route uses `vendors.view`; required shipping-quality read uses `shipping.view` |
| C-P3A-002 | Loyalty | `CAPABILITY_GAP` | `loyalty.view` surface exposes `loyalty.manage` writes |
| C-P3A-003 | Games / Production Readiness | `CAPABILITY_GAP` | view surfaces expose protected manage actions |
| C-P3A-004 | Admin index | `CONTRADICTORY_UI_BACKEND_PERMISSION` | role-gated route requires mandatory `analytics.view` read |
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
| C-P3A-016 | Vendors | `CONTRADICTORY_UI_BACKEND_PERMISSION / CROSS_DOMAIN_READ_DEPENDENCY` | mandatory seller-owner lookup also requires `users.view` |
| C-P3A-017 | Catalog index | `CAPABILITY_GAP` | `catalog.view` exposes review/category writes |
| C-P3A-018 | Promotions | `CAPABILITY_GAP` | `promotions.view` exposes create/lifecycle writes |
| C-P3A-019 | Tax | `CAPABILITY_GAP` | `tax.view` exposes jurisdiction/rate/profile writes |
| C-P3A-020 | Reviews | `ROLE_CAPABILITY_DRIFT` | role list substitutes for `reviews.moderate` |
| C-P3A-021 | Analytics | `CAPABILITY_GAP` | `analytics.view` exposes export/schedule management |
| C-P3A-022 | Risk | `CAPABILITY_GAP` | `risk.view` exposes holds/case mutations |
| C-P3A-023 | Media | `CAPABILITY_GAP` | `media.view` exposes upload/archive writes |
| C-P3A-024 | Operations Center | `CONTRADICTORY_UI_BACKEND_PERMISSION / CROSS_DOMAIN_READ_DEPENDENCY` | `operations.view` mandatory load composes finance-domain reads |
| C-P3A-025 | Operations Center | `CAPABILITY_GAP / FINANCIAL_ACTION_PRESENTATION` | finance/payout mutations lack `finance.manage` presentation gate |
| C-P3A-026 | Operations Center | `CAPABILITY_GAP / INCIDENT_COMMAND_PRESENTATION` | incident mutations lack `operations.manage` presentation gate |

## Positive controls retained

The audit has also identified local patterns that should be reused instead of inventing a new client authorization system:

- `AdminCompliance` separates base view permission from review/security/audit capabilities.
- `Acceptance` separates `acceptance.view`, `acceptance.manage`, `acceptance.sign` and `acceptance.seal`.
- `AdminAccess` is a source-verified read-only `/admin/rbac` surface under `users.view`; no mutation control is present in the component.
- dedicated `/admin/catalog/new` and `/admin/catalog/:id/edit` routes already enter under `catalog.manage`; later catalog repair should preserve that stronger route boundary.
- `OperationsCenter` consumes server-returned health/launch state rather than synthesizing unconditional green status; permission composition should be repaired without regressing this server-authoritative observability behavior.

## Systemic defect classes

### A — route/API authority contradictions

A route can be entered with one capability while its mandatory initial read requires another. Known instances:

- `/admin` → `analytics.view`;
- `/admin/seller-quality` → `shipping.view`;
- `/admin/vendors` → `users.view` seller-owner lookup;
- `/admin/operations` → finance-domain reads in addition to operations data.

These must be resolved before generic mutation-button gating because a view-only principal can otherwise enter a route that cannot successfully initialize.

### B — view/manage presentation drift

Repeated pattern:

`*.view route` → read succeeds → protected mutation controls are rendered → backend correctly requires `*.manage`.

This is not evidence to weaken backend RBAC. Later repairs should make the UI capability-aware using the existing `hasPermission(...)` mechanism.

### C — role-name authority drift

Order detail and review moderation use hard-coded role names where backend permission authority already exists. Later repair should remove the duplicate role-policy model rather than expand it.

### D — financial/security-sensitive presentation

Returns, finance, payouts, tax, risk and operations incident/financial actions require more than cosmetic hiding. Their later acceptance packages must explicitly preserve server-side authorization, confirmation where appropriate, idempotency, immutable/auditable attribution and negative authorization tests.

### E — observability truthfulness

AdminControl's synthetic green status is not authoritative health evidence. OperationsCenter provides the preferred server-derived model. Later repair should expose unknown/degraded/error states rather than infer health from unrelated page success.

## Proposed dependency-ordered repair packages — NOT ACTIVATED

1. **P3-R1 — route/data authority composition**
   - `/admin` analytics requirement;
   - Seller Quality shipping authority;
   - Vendors seller-owner lookup;
   - Operations Center operations/finance composition.
2. **P3-R2 — non-financial view/manage presentation**
   - users, vendors, catalog index, promotions, loyalty, games, media, notifications, settings, production readiness.
3. **P3-R3 — role-to-permission normalization**
   - order detail;
   - review moderation.
4. **P3-R4 — financial/security-sensitive presentation hardening**
   - shipping/payment sync where consequential;
   - returns;
   - tax;
   - finance/payouts;
   - risk holds/cases;
   - operations incident/finance controls.
5. **P3-R5 — operational observability correctness**
   - replace unsupported AdminControl success assertions with authoritative state or non-status presentation.
6. **P3-R6 — CRUD/state/loading/error/pagination completeness**
   - execute only after authorization contracts are stable; do not mix structural UX cleanup into security-boundary fixes.

Each later repair package must have a bounded path budget, explicit view-only/manage-capable negative tests, server contract proof, and its own CI/review evidence.

## Remaining P3-A acceptance work

Before P3-A itself is marked accepted:

1. reconcile the parent route matrix to these source-verified classifications;
2. explicitly mark already-verified read-only/permission-aware positive controls;
3. identify any route still only `BACKEND_MAPPED` without component-level read/mutation review;
4. record concrete later owner/path budget for every unresolved row;
5. verify the final audit head through CI + CodeQL + Governance Code Scanning;
6. record SELF REVIEW or independent review provenance;
7. merge audit/planning only; runtime repair must begin on a fresh later branch.

## Current decision

**P3-A remains IN PROGRESS.**

The audit has enough evidence to define the systemic repair architecture, but this ledger deliberately does not convert incomplete row verification into false acceptance and does not authorize implementation.