# P3-A — Catalog Editor Capability Composition Audit

Status: **SOURCE VERIFIED — PLANNING ONLY**

Routes:

- `/admin/catalog/new`
- `/admin/catalog/:id/edit`

Component: `AdminProductEditor` → shared `ProductEditor` in `resources/js/pages/CatalogManagement.jsx`.

## Baseline route authority

Both editor routes enter under `catalog.manage`. Core editor product operations use `/admin/products...` and `/admin/catalog`; backend RBAC maps those admin product/catalog/category paths to `catalog.view` for GET and `catalog.manage` for writes.

The shared `/tax/classes` supporting read is authenticated but is not under the admin/vendor area-specific RBAC mapper, so current source does not establish an additional admin permission requirement for that supporting lookup.

## C-P3A-027 — catalog editor has mandatory cross-domain media-library read dependency

The editor unconditionally renders:

`<MediaLibraryPanel mode="admin" ... />`

Admin media-library mode loads `/admin/media-library`. Backend RBAC maps that admin surface to `media.view` for reads.

Therefore a principal can legitimately satisfy route entry `catalog.manage` and the product/catalog contracts while still failing the editor's reusable-media panel load if `media.view` is absent.

Classification: **CONTRADICTORY_UI_BACKEND_PERMISSION / CROSS_DOMAIN_READ_DEPENDENCY**.

Later repair must explicitly choose and document one of these contracts rather than silently broadening authority:

1. require `media.view` in addition to `catalog.manage` at editor entry; or
2. make the reusable-media panel capability-aware/optional while preserving catalog editing; or
3. expose a narrow catalog-owned reusable-media picker contract with explicit server authority.

Backend RBAC must not be weakened simply to make the editor render.

## C-P3A-028 — catalog editor exposes media-library management controls without `media.manage`

`MediaLibraryPanel` in admin mode exposes reusable-media upload and archive controls. Backend `/admin/media-library` writes require `media.manage`.

The catalog editor itself is entered under `catalog.manage` and does not establish `media.manage` before rendering those controls.

Classification: **CAPABILITY_GAP / CROSS_DOMAIN_MEDIA_MANAGEMENT_PRESENTATION**.

This is distinct from product-specific media attachment operations such as `/admin/products/{product}/media...`: those product-owned paths are currently mapped by backend RBAC to the catalog domain. The defect is specifically reusable media-library authority being composed into the catalog editor without an explicit media capability boundary.

## Create vs edit behavior

Both `/admin/catalog/new` and `/admin/catalog/:id/edit` share the same unconditional MediaLibraryPanel composition, so C-P3A-027 and C-P3A-028 apply to both routes.

For new products, selected library items may be queued and then attached after product creation; for existing products they may be attached immediately. This behavior does not change the requirement to authorize the reusable-media library separately from catalog-product writes.

## Repair-order effect

These findings belong in P3-R1/P3-R2 before generic editor UX cleanup:

1. resolve the `catalog.manage` + `media.view` composition contract;
2. gate reusable-media upload/archive on `media.manage`;
3. preserve catalog-owned product media attachment authority;
4. test catalog-manage-only, catalog+media-view and media-manage-capable principals independently;
5. retain managed-media identity/stable-reference constraints from P2.

No runtime or permission repair is authorized by this audit.