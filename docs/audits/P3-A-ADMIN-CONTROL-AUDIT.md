# P3-A — Admin Control Capability Audit

Status: **SOURCE-VERIFIED / PLANNING ONLY**

Working lane: `agent/20260830-vsn-commerce-abd-196`
Parent matrix: `docs/audits/P3-A-ADMIN-DOMAIN-CONTRACT-MATRIX.md`

## Scope

This slice audits only the `/admin` index (`AdminControl`) contract. It does not authorize a runtime, RBAC, schema, API or React repair.

Source evidence:

- `resources/js/App.jsx`
- `resources/js/pages/Systems.jsx`
- `resources/js/pages/SystemsServer.jsx`
- `app/Security/Rbac.php` as already reconciled in the parent P3-A matrix

## Finding C-P3A-004 — admin index route does not declare the capability required by its mandatory data request

### Evidence

1. The `/admin/*` parent is protected by the admin-area role gate.
2. The `/admin` index renders `AdminControl` directly and has no `RequirePermission` wrapper.
3. `AdminControl` immediately performs `GET /admin/analytics` as its mandatory page data request.
4. The reconciled backend contract requires `analytics.view` for the admin analytics GET surface.
5. `AdminControl` uses `hasPermission(...)` only to conditionally render navigation links after the analytics request succeeds; it does not use `analytics.view` to guard the page's mandatory request.

### Impact

An admin-area principal that is role-eligible for `/admin` but intentionally lacks `analytics.view` can enter the route and then receive a backend authorization failure for the page's mandatory request. Backend authorization remains fail-closed, but the route-entry contract and the page's required server capability are contradictory.

Classification: **CONTRADICTORY_UI_BACKEND_PERMISSION**

### Bounded repair candidates for a later authorized package

Choose one explicitly; do not weaken backend RBAC:

- require `analytics.view` at the `/admin` route entry if analytics is intentionally the required dashboard authority; or
- change `AdminControl` to compose only capability-specific optional panels, with a valid non-analytics base state for admin-area principals that lack analytics access.

The second option is broader and requires a defined server contract for each visible dashboard panel before implementation.

## Finding C-P3A-005 — system-health badges assert success without corresponding health evidence

### Evidence

After loading `/admin/analytics`, `AdminControl` renders five unconditional success statuses:

- Laravel catalog API
- Finance ledger
- Game scheduler
- Affiliate engine
- Notification queue

The component does not fetch a health/readiness endpoint for these systems and does not derive these five status values from the analytics response before rendering them as `ok`.

### Impact

The UI can display healthy system assertions when the underlying subsystem has not been checked by this route. This is not an authorization bypass, but it is an operational-observability correctness defect and can mislead an administrator during an incident.

Classification: **UNVERIFIED_OPERATIONAL_ASSERTION**

### Bounded repair candidates for a later authorized package

- bind health indicators to an authoritative health/readiness contract with explicit unknown/degraded/error states; or
- remove the success semantics and present the section as navigation/informational capability labels until real health evidence exists.

Do not synthesize a green status from route load success or from unrelated analytics availability.

## Positive control retained

The `Marketplace controls` navigation inside `AdminControl` already checks per-domain `*.view` capabilities before rendering links. A later repair should preserve that deny-by-default presentation pattern rather than replace it with a new client-side permission framework.

## Parent-matrix reconciliation

The `/admin` row should be treated as:

- route-entry authority: admin-area role gate;
- mandatory page API authority: `analytics.view`;
- state: `CONTRADICTORY_UI_BACKEND_PERMISSION` until a bounded repair is accepted;
- additional observability defect: `C-P3A-005`.

No runtime repair is authorized by this audit file.