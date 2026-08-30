# P3-A — Admin Core Capability Audit

Status: **SOURCE VERIFIED — PLANNING ONLY**

Repository: `Vertex-Systems-Network/vsn-commerce`
Lane: `agent/20260830-vsn-commerce-abd-196`
Phase: `P3-A`

This artifact extends the admin-domain contract matrix with direct component-level evidence for users, vendors, catalog, promotions, tax, reviews, analytics, risk and media. It does not authorize runtime, controller, RBAC, schema or React behavior changes.

## Guardrail

Backend RBAC remains authoritative. A client-side capability gap is not a backend bypass; it is a presentation/workflow contract defect that creates controls a view-only principal cannot legitimately execute. Later repair must hide/disable privileged controls or align route authority without weakening server authorization.

## C-P3A-014 — Users route exposes `users.manage` actions to `users.view`

Source: `resources/js/pages/AdminUsers.jsx`.

Observed:

- route entry is `users.view`;
- list/search reads `/admin/users`;
- create posts `/admin/users`;
- role change puts `/admin/users/{id}`;
- the component has no permission-aware split between read and mutation surfaces;
- create form, create button and role selector are always rendered for any principal that can enter the route.

Classification: `CAPABILITY_GAP`.

Later repair owner: admin users presentation boundary.

Expected bounded repair: preserve `users.view` list/search; gate account creation and role mutation on `users.manage`; add source/browser proof for view-only and manage principals.

## C-P3A-015 — Vendors route exposes `vendors.manage` actions to `vendors.view`

Source: `resources/js/pages/AdminVendors.jsx`.

Observed:

- route entry is `vendors.view`;
- vendor list reads `/admin/vendors`;
- create posts `/admin/vendors`;
- marketplace status change puts `/admin/vendors/{id}`;
- create and status mutation controls render without a `vendors.manage` capability check.

Classification: `CAPABILITY_GAP`.

Later repair must preserve the read-only vendor list while gating create/status mutation on `vendors.manage`.

## C-P3A-016 — Vendors route has a hidden `users.view` data dependency

Source: `resources/js/pages/AdminVendors.jsx` plus the already verified backend contract for `/admin/users`.

The vendor page performs its mandatory initial load with:

- `/admin/vendors`;
- `/admin/users?role=seller&perPage=100`.

The route itself is entered on `vendors.view`, but the seller-owner lookup requires the separate backend `users.view` permission. A principal that legitimately has `vendors.view` without `users.view` can therefore enter the route and fail its combined load.

Classification: `CONTRADICTORY_UI_BACKEND_PERMISSION` / `CROSS_DOMAIN_READ_DEPENDENCY`.

Later repair must not grant `users.view` implicitly. Preferred planning options are to expose a vendor-domain seller-owner lookup with bounded fields/authority, or deliberately require/document the additional permission at route entry if product policy truly requires it.

## C-P3A-017 — Catalog index mixes `catalog.view` and `catalog.manage`

Source: `resources/js/pages/CatalogManagement.jsx` (`AdminCatalog` / `CategoryManager`).

Observed from the `/admin/catalog` view route:

- catalog reads use `catalog.view`;
- product publish/suspend review posts `/admin/products/{id}/review`;
- category creation posts `/admin/categories`;
- category activation/deactivation puts `/admin/categories/{id}`;
- mutation controls render in the view surface without a `catalog.manage` presentation gate.

Classification: `CAPABILITY_GAP`.

The dedicated new/edit product routes already enter on `catalog.manage`; that route-level boundary should be retained. The later repair should focus the index/review/category mutation controls rather than redesigning the product editor.

## C-P3A-018 — Promotions route exposes lifecycle writes to `promotions.view`

Source: `resources/js/pages/Promotions.jsx` (`AdminPromotions` / `PromotionManager`).

Observed:

- route entry is `promotions.view`;
- admin promotion read is separated server-side from create/status writes;
- create draft, activate, pause and end controls are rendered without a `promotions.manage` client capability check;
- the form includes funding, limits, time windows and vendor restriction, making the mutation surface commercially consequential.

Classification: `CAPABILITY_GAP`.

Later repair must gate create/lifecycle controls on `promotions.manage` while retaining view-only campaign reporting.

## C-P3A-019 — Tax administration exposes `tax.manage` actions to `tax.view`

Source: `resources/js/pages/Tax.jsx` (`AdminTax`).

Observed mutation surfaces include:

- create jurisdiction;
- create tax class;
- create tax rate;
- enable/disable a rate;
- approve a seller tax profile.

All render on the `tax.view` route without a corresponding manage-capability presentation boundary.

Classification: `CAPABILITY_GAP`.

Tax mutations affect calculation/collection behavior and seller compliance state. Later implementation acceptance must include explicit permission coverage and audit attribution; it must not be treated as cosmetic button hiding only.

## C-P3A-020 — Review moderation uses role-name authority instead of `reviews.moderate`

Source: `resources/js/pages/AdminReviews.jsx`.

The component computes:

`canModerate = ['moderator','admin','super_admin'].includes(user?.role)`

and uses that role list to expose approve/reject/report-resolution actions. Backend authority is permission-based (`reviews.moderate`).

Classification: `ROLE_CAPABILITY_DRIFT`.

This can diverge in either direction when custom role/permission assignments evolve. Later repair should use the existing permission authority pattern rather than expanding the hard-coded role list.

## C-P3A-021 — Analytics mutations are exposed on `analytics.view`

Source: `resources/js/pages/AdminAnalytics.jsx`.

Read-only analytics and financially/operationally meaningful report-management actions share one surface. The component exposes without an `analytics.manage` presentation gate:

- queue CSV export;
- create scheduled report;
- enable/disable scheduled report;
- delete scheduled report.

Classification: `CAPABILITY_GAP`.

The private-export model, checksum/private-storage semantics and no-arbitrary-recipient policy are positive existing controls and should remain unchanged. Later repair is capability separation, not report architecture replacement.

## C-P3A-022 — Risk center exposes `risk.manage` controls on `risk.view`

Source: `resources/js/pages/Risk.jsx`.

The view surface renders:

- create scoped hold;
- re-evaluate user/vendor risk;
- change risk-case state;
- resolve cases;
- release holds.

These are protected operational/security mutations but there is no `risk.manage` presentation split.

Classification: `CAPABILITY_GAP`.

Because holds can affect payments, wallet, games, returns, affiliate rewards, payouts or all sensitive actions, later repair requires manage capability, confirmation where appropriate, attributable audit evidence and negative authorization tests.

## C-P3A-023 — Media library mixes `media.view` with upload/archive authority

Sources:

- `resources/js/pages/AdminMedia.jsx`;
- `resources/js/components/MediaLibraryPanel.jsx`.

`AdminMedia` delegates the entire route to `MediaLibraryPanel mode="admin"`. The panel always renders an upload input and, for admin mode, archive buttons while the route enters on `media.view`. Backend writes are mapped separately to `media.manage`.

Classification: `CAPABILITY_GAP`.

Positive control retained: archive asks for confirmation and managed-media identity is server-owned. Later repair should capability-gate upload/archive without weakening P2 managed-media identity, stable references or non-destructive compatibility.

## Cross-domain conclusions

This source slice strengthens the repeated P3-A pattern:

`*.view route` → `read + privileged mutation UI` → backend deny-by-default `*.manage`.

It also adds a separate class of defect:

`domain.view route` → mandatory read into another admin domain → hidden second permission requirement.

The vendor-owner lookup is the current concrete example.

## Repair-order effect

When the full P3-A matrix is accepted, the implementation sequence should separate:

1. **permission-contract contradictions / hidden cross-domain reads** before generic button gating;
2. **view/manage presentation fixes** using the existing `hasPermission(...)` pattern;
3. **role-to-permission drift** removal;
4. **financial/security-sensitive mutations** with confirmation, audit and negative authorization evidence;
5. **state/CRUD/loading/error/pagination gaps** after authorization correctness is stable.

No runtime repair is authorized by this audit artifact.
