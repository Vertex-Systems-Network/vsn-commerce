# VSN Ecommerce — Existing-Project Adoption Audit

Audit date: 2026-08-28

Repository: `Vertex-Systems-Network/vsn-commerce`

Baseline branch: `main`

Baseline head: `a9e6919ba03a6ab4e9dcc44f3c7fe3278e3fe60e`

Audit mode: read-only inspection followed by additive documentation and focused P0-02 metadata reconciliation. No application business code, schema, dependencies, branch protection, deployment or production setting is changed by this audit package.

## 1. Project classification

Current project state: `ACTIVE_EXISTING_PROJECT`

Operational posture: pre-production stabilization/completion with substantial implementation and production-readiness tooling already present.

Do not restart or rebuild this project. Preserve the unified Laravel + React architecture and existing API/business contracts unless a defect is proven.

The repository already contains an effective project-memory mechanism in `AGENTS.md` plus `docs/MASTER-EXECUTION-STATUS.json`. Do not add a duplicate `.ai/` memory tree unless those mechanisms are intentionally replaced.

## 2. Evidence authority

For actual state, use this order:

1. current repository/code
2. current database/schema/config contracts
3. observed execution
4. executed tests and CI evidence
5. VCS history
6. approved documentation
7. canonical execution-state document
8. previous AI/chat claims

README milestone prose is not proof of runtime correctness.

## 3. Current P0 reconciliation

### P0-01 — dependency lock

Status: `VERIFIED RESOLVED`

The original `AGENTS.md` audit claim that root `composer.lock` is missing is stale. Current repository reality contains a committed Composer lock and canonical P0 acceptance evidence.

Do not regenerate dependencies during unrelated work.

### P0-02 — project license contradiction

Status: `IMPLEMENTED / VERIFYING`

The repository owner explicitly confirmed on 2026-08-28 that VSN Commerce is **not GPLv3**. Project-level intent is proprietary / closed-source.

Repository reconciliation:

- `composer.json` already declares `"license": "proprietary"` and does not require a change;
- root GPLv3 license text is replaced by a narrow proprietary software notice;
- `docs/decisions/ADR-0001-PROPRIETARY-PROJECT-LICENSE.md` records the owner decision and scope;
- third-party dependencies remain under their own licenses.

P0-02 should not be called fully closed until the focused PR/checks are accepted and merged.

## 4. Server-authority plan reconciliation

Several major customer flows are already Laravel-authoritative and should not be rewritten merely for uniformity:

- Coins
- Games
- Reviews
- Affiliate
- Home marketplace data

Residual dual/static authority is concentrated in a smaller set of surfaces:

- `resources/js/pages/Product.jsx`
- `resources/js/pages/Search.jsx`
- `resources/js/pages/Systems.jsx`
- `resources/js/main.jsx` global `StoreProvider`
- `resources/js/platform/store.jsx`
- `resources/js/data/catalog.js`

`resources/js/platform/api.js` currently hardcodes Laravel as the backend. Therefore many `Legacy*` branches are unreachable in current production mode, but they still create dual-authority source code, dead fallback complexity and regression risk.

The correct P1 strategy is targeted residual cleanup, not a customer-frontend rewrite.

## 5. P1 small-batch work packages

### P1-A — Search legacy catalog removal

Classification: `COMPLETION`

- remove production/runtime dependency on `data/catalog.js` from `Search.jsx`;
- remove unreachable legacy category/product calculations;
- preserve API search, facets, pagination, trending and recent search behavior;
- API failure must remain an error/empty state, never demo data;
- add regression coverage rejecting live-route static-catalog imports.

### P1-B — Product legacy authority removal

Classification: `COMPLETION / HARDENING`

- remove static catalog/demo product fallback from Laravel product mode;
- remove `useStore()` business operations from production product behavior;
- preserve Laravel wallet, games, gifts, reviews, alerts, wishlist and catalog adapters;
- eliminate hardcoded demo review/image paths from production behavior;
- preserve explicit loading/not-found/error states;
- test API failure and review/game/gift/cart integrations.

### P1-C — Customer systems legacy branches

Classification: `COMPLETION / REFACTOR`

Split cleanup by domain rather than deleting all `Systems.jsx` legacy code in one giant diff. Candidate order:

1. Orders + Tracking
2. Checkout
3. Wallet
4. Notifications + Messages
5. Settings
6. Gifts
7. Returns + Alerts
8. Operations/Seller-quality compatibility surfaces

For each batch:

- remove unreachable `Legacy*` branch;
- remove corresponding `useStore()` dependencies;
- verify Laravel behavior first;
- targeted tests/build before proceeding.

### P1-D — retire global legacy StoreProvider

Classification: `COMPLETION`

Only after all live consumers are removed:

- unmount `StoreProvider` from `main.jsx`;
- remove `platform/store.jsx` if no development/test consumer legitimately remains;
- remove or relocate `data/catalog.js` to an explicit test/dev-fixture boundary;
- add a governance/static rule preventing production live routes from importing legacy fixture authority.

Do not delete the provider first and repair breakage afterward.

## 6. Media plan reconciliation

### Product managed media

Status: `PARTIAL → SUBSTANTIALLY IMPLEMENTED`

Current `CatalogManagement.jsx` keeps product business-field saves separate from media attachment operations and uses managed media/library endpoints. Historical unmanaged media is identified separately.

Do not repeat the old product-media rewrite blindly. Verify lifecycle and migration gaps first.

### Seller storefront logo

Status: `PARTIAL / CONTRACT CLEANUP`

Backend behavior is materially ahead of the original audit:

- stable `logoMediaAssetId` is persisted;
- presentation URL is resolved from the media asset;
- seller/global ownership rules are enforced;
- cross-vendor selection is rejected;
- compatibility URL input is normalized to a stable asset reference.

Remaining cleanup:

- frontend `SellerSettings` should submit the selected media asset ID directly instead of relying on `item.url` compatibility;
- retain the compatibility resolver only as a bounded migration path where justified;
- verify archive/reference behavior before removing compatibility code.

Existing `MarketplaceMediaStorefrontTest` already covers stable ID persistence, URL normalization, cross-vendor denial and public URL resolution.

## 7. Security/data observations

Current API route structure includes authenticated boundaries, role middleware, throttles, provider-webhook routing and server-side seller resource scoping. These are positive indicators, not blanket proof of all security properties.

High-value negative requirements to preserve/test during P1:

- API failure MUST NOT fall back to fake/static business data;
- browser localStorage MUST NOT become authoritative for balances, orders, inventory, returns, coupons, shipping prices or financial state;
- one seller MUST NOT select/read another seller's private media;
- customer/seller/admin isolation MUST remain server-enforced;
- demo accounts MUST remain impossible to seed/use accidentally in production.

## 8. Testing strategy for the next engineering packages

Fast gate per small batch:

- changed-source lint/static checks;
- affected frontend source audit/build;
- focused PHPUnit tests for touched API/domain contracts;
- focused Playwright route/behavior test where user behavior changes.

Milestone gate after legacy authority removal:

- full Laravel unit/feature matrix;
- SQLite/MySQL/PostgreSQL compatibility where currently supported;
- production frontend build;
- browser E2E/accessibility regression;
- governance/security checks;
- runtime integration gate.

Do not rerun a flaky failure until it happens to pass and call it green.

## 9. Admin migration sequencing

Do not start a broad Untitled UI rewrite while P1 authority cleanup remains uncertain on shared data paths.

Safe order remains:

1. P0 release blocker closure
2. P1 residual server-authority cleanup
3. remaining stable media-reference cleanup
4. admin functional/API verification
5. scoped Admin design-system foundation
6. AdminShell
7. admin pages in representative small batches
8. full regression/certification

Customer storefront visual design remains out of scope for the Admin UI migration.

## 10. Current next safe action

For this branch/PR:

1. validate the proprietary project-level license reconciliation;
2. run/observe repository CI and metadata checks;
3. merge only when the focused P0-02 change is accepted under repository protections;
4. update canonical execution state to mark P0-02 complete with exact merge evidence;
5. then begin P1-A Search legacy catalog removal as the first small implementation batch.

Do not start broad P1 application changes inside the P0-02 licensing PR.
