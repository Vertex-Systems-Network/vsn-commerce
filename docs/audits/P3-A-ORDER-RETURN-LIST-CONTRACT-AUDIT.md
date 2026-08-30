# P3-A — Order & Return List Contract Audit

Status: **SOURCE VERIFIED — PLANNING ONLY**

Routes:

- `/admin/orders`
- `/admin/returns`

Component: `AdminOrders` / `AdminReturns` in `resources/js/pages/AdminOperations.jsx`.

## Authorization and mutation boundary

### `/admin/orders`

Route entry is `orders.view`. The list component performs only `GET /admin/orders` plus local search/status/payment filters and links to detail pages. No list-level order mutation control is rendered.

Backend deny-by-default RBAC maps GET `/admin/orders` to `orders.view`. `AdminOrderController::viewer()` currently permits the same role set that owns `orders.view` in `config/rbac.php` (support, finance, admin, super_admin), so no current list-level authorization contradiction is proven here. The controller role list remains duplicated policy and should not be expanded independently of the permission map.

### `/admin/returns`

Route entry is `returns.view`. The list component performs only `GET /admin/returns` plus local search/status filters and links to return/order details. No list-level return/refund/dispute mutation control is rendered; privileged mutations remain in the already-classified detail surface.

Backend deny-by-default RBAC maps GET `/admin/returns` to `returns.view`. Current `config/rbac.php` grants `returns.view` to admin/super_admin, matching `AdminReturnController::admin()` for the present role model. No current list-level authorization contradiction is proven here.

## C-P3A-029 — order list drops server pagination contract

`AdminOrderController::index()` paginates with a default page size of 30 and returns `meta.total`, `meta.currentPage`, `meta.lastPage`, and `meta.perPage`.

`AdminOrders` stores the returned metadata but never sends a `page` parameter and renders no next/previous/page-size control. Therefore orders after the first server page are not reachable from this surface even though the API exposes pagination state.

Classification: **PAGINATION_CONTRACT_GAP / LIST_COMPLETENESS**.

Later repair must preserve the read-only `orders.view` boundary while consuming the server pagination contract. Search/filter changes should reset to page 1, page navigation must retain active filters, and loading/error state must not silently present an empty first-page snapshot as a complete result set.

## C-P3A-030 — return list drops server pagination contract

`AdminReturnController::index()` uses `paginate(30)` and returns `meta.total`, `meta.currentPage`, and `meta.lastPage`.

`AdminReturns` never sends a `page` parameter and renders no pagination controls. Returns after the first 30 records are therefore unreachable from the list UI.

Classification: **PAGINATION_CONTRACT_GAP / LIST_COMPLETENESS**.

Later repair must consume the existing server pagination metadata without mixing return-detail mutation repair into this list-only package.

## Loading/error/search observations

Both lists:

- initiate a GET on mount;
- re-run on status-filter changes;
- support Enter/search button execution for text search;
- display request errors through `Status`;
- have no explicit loading state during initial or subsequent fetches;
- do not clear the previous error before a later successful retry in the current component code.

Those loading/retry semantics belong to the later P3-R6 CRUD/state completeness package unless a security-sensitive repair requires a narrower change first.

## Queue decision

Q-03 and Q-04 are now source-verified. Both list surfaces are read-only at component level. Their concrete gaps are pagination/list completeness rather than hidden mutations.

No runtime/UI/controller/RBAC repair is authorized by this document.