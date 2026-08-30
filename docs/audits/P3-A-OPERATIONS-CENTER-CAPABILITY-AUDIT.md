# P3-A — Operations Center Capability Composition Audit

Status: **SOURCE VERIFIED — PLANNING ONLY**

Repository: `Vertex-Systems-Network/vsn-commerce`  
Lane: `agent/20260830-vsn-commerce-abd-196`  
Parent matrix: `docs/audits/P3-A-ADMIN-DOMAIN-CONTRACT-MATRIX.md`

## Scope

This artifact audits only `/admin/operations` / `OperationsCenter` from `resources/js/pages/SystemsServer.jsx`. It does not authorize runtime, RBAC, API, schema or React repair.

## C-P3A-024 — Operations route has mandatory cross-domain finance read dependencies

Route entry is `operations.view`.

`OperationsCenter` performs one mandatory combined load using:

- `GET /admin/finance`;
- `GET /admin/finance/payouts`;
- `GET /admin/finance/payout-batches`;
- `GET /admin/system/operations`.

The first three surfaces belong to the finance authority boundary while the route advertises only `operations.view`. The page waits on `Promise.all(...)`, so a principal that is legitimately allowed to inspect operations but lacks finance read authority can enter the route and then fail the entire mandatory page load.

Classification: **CONTRADICTORY_UI_BACKEND_PERMISSION / CROSS_DOMAIN_READ_DEPENDENCY**.

This is not a reason to grant finance access implicitly. A later bounded repair must explicitly choose the product contract:

1. require the composed `operations.view` + `finance.view` authority at route entry; or
2. split operational health/incident data from finance/payout panels so each panel loads only when its own capability is present; or
3. introduce a narrowly scoped server composition contract whose returned fields and authority are explicit.

Backend fail-closed behavior must not be weakened merely to preserve the current page composition.

## C-P3A-025 — Operations Center exposes finance mutations without `finance.manage` presentation authority

The same route renders financially consequential actions including:

- payout review/approval/rejection;
- mark payout paid;
- payout cancellation;
- payout batching;
- finance reconciliation.

These mutation functions POST to finance-domain endpoints while no component-level `finance.manage` capability check is applied before the controls are rendered.

Classification: **CAPABILITY_GAP / FINANCIAL_ACTION_PRESENTATION**.

Later repair must retain server-side ledger/idempotency/maker-checker constraints and additionally ensure view-only principals never receive privileged finance controls as executable UI.

## C-P3A-026 — Operations Center exposes incident mutations without `operations.manage` presentation authority

The page also exposes incident operations:

- add operator note;
- change incident status to investigating/monitoring;
- resolve incident.

Those actions mutate `/admin/system/operations/incidents/...` without a component-level `operations.manage` presentation gate.

Classification: **CAPABILITY_GAP / INCIDENT_COMMAND_PRESENTATION**.

Later repair must require the exact backend manage authority, preserve append-only incident attribution, and include negative authorization tests for an `operations.view`-only principal.

## Positive control

Unlike the older `/admin` control index, `OperationsCenter` obtains health/launch data from `/admin/system/operations` and renders the returned health/check state rather than synthesizing unconditional green statuses. The later permission repair should preserve that server-authoritative observability model.

## Repair-order effect

This route should be addressed before generic view/manage button gating because its mandatory load composes independent permission domains.

Recommended later order:

1. decide and document the intended operations/finance composition contract;
2. make page/panel loading capability-aware without weakening backend RBAC;
3. gate incident mutations on `operations.manage`;
4. gate finance/payout mutations on `finance.manage`;
5. retain confirmation, idempotency, ledger/audit and incident attribution semantics;
6. prove `operations.view`-only, `finance.view`-only, combined-view and manage-capable cases.

No repair is authorized by this audit file.