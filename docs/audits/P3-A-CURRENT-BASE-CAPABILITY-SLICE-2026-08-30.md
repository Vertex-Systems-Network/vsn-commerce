# P3-A — Current-Base Reconciliation and Capability Slice

Status: **IN PROGRESS — AUDIT / PLANNING ONLY**

Repository: `Vertex-Systems-Network/vsn-commerce`
Working lane: `agent/20260830-vsn-commerce-abd-196`
PR: `#48`
Current protected-main / PR base: `563699ca66a4de1d9fc4cce60eb4f6ef91e71f01`
Observed PR head before this audit slice: `f0aeb7b9f126d55e2f4eb44eb675a342235f45a4`

## Why this reconciliation exists

The parent matrix currently records historical source base `730d36f9c7970f4ac358e44e670b097061b51a45`, while the live P3-A carrier is based on `563699ca66a4de1d9fc4cce60eb4f6ef91e71f01` after accepted P2 closure.

Until the parent matrix and canonical execution-state metadata are atomically reconciled, this file is the branch-local current-base checkpoint. It does not replace protected-main authority and must be folded into the canonical P3-A acceptance transaction before P3-A is marked complete.

## Source reviewed in this slice

- `resources/js/pages/AdminUsers.jsx`
- `resources/js/pages/AdminVendors.jsx`
- `resources/js/pages/AdminReviews.jsx`
- existing route/API/RBAC mapping in the P3-A parent matrix

Backend authorization remains authoritative. UI behavior is never treated as proof of server permission.

## C-P3A-014 — Users page exposes `users.manage` mutations to `users.view` route principals

`/admin/users` enters on `users.view`. The component immediately renders:

- create-user form and `POST /admin/users`;
- per-user role selector and `PUT /admin/users/{id}`.

The existing backend map requires `users.manage` for those writes. `AdminUsers.jsx` does not read a permission capability or conditionally hide/disable the mutation surfaces.

Classification: `CAPABILITY_GAP`.

Later repair requirement: preserve `users.view` read access while presenting create/role mutation controls only to `users.manage`; backend deny-by-default enforcement must not be weakened.

Additional state findings:

- loading state is not explicitly modeled;
- empty-table state is implicit rather than intentional;
- errors are surfaced;
- search supports explicit submit / Enter plus role filtering;
- pagination metadata is read but no page navigation is rendered in this component.

Potential secondary classifications for later completeness mapping: `LOADING_STATE_GAP`, `EMPTY_STATE_GAP`, `PAGINATION_UI_GAP`.

## C-P3A-015 — Vendors page has view/manage drift and a hidden cross-domain read dependency

`/admin/vendors` enters on `vendors.view`. `AdminVendors.jsx` renders:

- vendor create via `POST /admin/vendors`;
- vendor status mutation via `PUT /admin/vendors/{id}`;

while the backend map requires `vendors.manage` for writes. No capability check is present.

Classification: `CAPABILITY_GAP`.

The page also loads seller owners through `GET /admin/users?role=seller&perPage=100`. That creates a cross-domain read dependency on the Users API. A principal with `vendors.view` but without the Users read authority may therefore enter the Vendors route and fail its mandatory combined load.

Classification: `CROSS_DOMAIN_READ_AUTHORITY_DEPENDENCY` until backend permission compatibility is proven and intentionally documented.

Later repair must either:

- require the minimum combined read capabilities at route/page composition; or
- expose a vendor-owned seller-owner lookup contract with bounded fields and intentional authority.

Do not weaken Users authorization merely to make Vendors load.

Additional state findings:

- no explicit loading state;
- no explicit empty state;
- no retry action after error;
- create validation is mostly client-side presence checks, with server validation remaining authoritative.

## C-P3A-016 — Review moderation uses hard-coded roles instead of `reviews.moderate`

`/admin/reviews` enters on `reviews.view`; backend moderation mutations require `reviews.moderate`.

`AdminReviews.jsx` derives mutation presentation from hard-coded roles:

`moderator | admin | super_admin`

and then exposes review moderate / report resolve mutations when that role test passes.

Classification: `ROLE_CAPABILITY_DRIFT`.

A role name must not substitute for the server permission authority. Later repair should use the existing permission-capability model and preserve backend `reviews.moderate` enforcement.

Positive state behavior already present and should be preserved:

- explicit loading state;
- explicit empty states for review/report queues;
- error state;
- privileged actions are at least presentation-gated today, although by the wrong authority primitive.

## Test/evidence mapping delta

The repository has strong broad CI, database suites and Playwright/browser gates, but the current frontend package does not expose a conventional component/unit test runner for focused React permission-presentation tests.

P3 planning should therefore distinguish:

1. existing server/API authorization tests — retain and map where present;
2. existing browser/E2E coverage — reuse where it proves route/user journeys;
3. missing focused component-level permission-presentation proof — add only in a later authorized testing/hardening package if existing source/E2E tests cannot prove the negative UI requirements cheaply and durably.

Do not introduce a test framework inside P3-A merely to satisfy this audit document.

## Updated priority implications

The provisional P3 repair sequence should now include:

- **R1** route/API permission alignment: `/admin`, Seller Quality, and Vendors cross-domain mandatory-read authority;
- **R2** capability-aware presentation: Users, Vendors, Loyalty, Games, Shipping, Payments, Returns detail, Finance, Payouts, Notifications, Settings, Production Readiness;
- **R2b** remove hard-coded role authority where backend permissions exist: Order Detail and Reviews;
- **R3** retain stronger confirmation/audit/idempotency gates for financial/destructive actions;
- **R4** operational health assertions;
- **R5** loading/empty/error/retry/filter/pagination/CRUD completeness;
- **R6** dependency-ordered bounded implementation packages after full P3-A acceptance.

## Exact next safe action

Continue the remaining admin-domain source audit on this branch. Before P3-A completion:

1. reconcile the parent matrix source-base metadata to the current accepted P2 base;
2. reconcile canonical execution-state observed PR/base/head fields using the repository's accepted state-transaction convention;
3. finish capability/workflow/state/test mapping for all routes;
4. freeze path-budgeted R1–R6 work packages;
5. run exact-head full gates;
6. record SELF REVIEW vs independent review explicitly;
7. only then open the first runtime-repair package.

No runtime, RBAC, schema, API or React behavior is changed by this audit slice.
