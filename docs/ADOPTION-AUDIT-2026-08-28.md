# VSN Ecommerce — Existing-Project Adoption Audit

Audit date: 2026-08-28

Repository: `Vertex-Systems-Network/vsn-commerce`

Baseline branch: `main`

Baseline head: `a9e6919ba03a6ab4e9dcc44f3c7fe3278e3fe60e`

Audit mode: read-only inspection followed by this additive documentation-only reconciliation. No application code, schema, dependency, license, branch protection, deployment or production setting was changed by this audit.

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

## 3. Current canonical milestone

`docs/MASTER-EXECUTION-STATUS.json` identifies:

- Package: `P0 — Truth baseline and release blockers`
- Package status: `IN_PROGRESS`
- Current task: `P0-02 — Resolve repository license metadata contradiction`
- Current task status: `BLOCKED`

This blocker is valid. Root `LICENSE` is GPL v3 while `composer.json` declares `proprietary`.

Do not infer the intended license. An explicit repository-owner/legal decision is required before changing either license representation.

Until that decision exists, P0-02 remains the critical-path blocker.

## 4. Plan-vs-repository reconciliation

### ADOPT-001 — P0-01 dependency lock blocker is stale in `AGENTS.md`

Status: `CORRECTION / VERIFIED RESOLVED`

Current repository reality:

- root `composer.lock` exists
- P0-01 reconciliation and merge-closure commits exist in history
- canonical execution state lists `P0-01` as completed
- accepted CI evidence is recorded for the P0 baseline

Action for future plan maintenance:

- change P0-01 from missing/blocker to completed/verified
- retain the requirement that CI and production installs use the committed lock
- do not regenerate dependencies opportunistically during unrelated work

### ADOPT-002 — server-authoritative migration is partially complete, not uniformly missing

Status: `CORRECTION / COMPLETION`

Several major customer flows are already Laravel-authoritative and fail without substituting browser business state, including the dedicated Coins, Games, Reviews, Affiliate and Home implementations.

Do not rewrite these working API-backed flows merely to make the frontend uniform.

Remaining work should target residual dual-authority code only.

### ADOPT-003 — production backend selection is currently fixed to Laravel

Status: `VERIFIED_PRESENT`

`resources/js/platform/api.js` defines the backend as Laravel directly. Therefore many `apiBackend !== 'laravel'` branches are currently unreachable under the shipped runtime contract.

This reduces immediate runtime fallback risk, but the dead legacy implementations still create maintenance, bundle, testing and regression risk.

Do not reinterpret this as permission to keep the duplicate business engine indefinitely.

### ADOPT-004 — residual static/legacy customer paths remain

Status: `COMPLETION / HIGH PRIORITY`

Confirmed residuals include:

- `resources/js/data/catalog.js` hardcoded marketplace catalog
- `resources/js/platform/store.jsx` localStorage business engine
- `resources/js/pages/Product.jsx` imports both static catalog and `useStore`
- `resources/js/pages/Search.jsx` imports static catalog/category data for legacy branches
- `resources/js/pages/Systems.jsx` contains many paired `Laravel*` and `Legacy*` implementations
- global `StoreProvider` is still mounted in `resources/js/main.jsx`

`Systems.jsx` legacy coverage includes, among others:

- Orders
- Checkout
- Tracking
- Wallet
- Notifications
- Messages
- Settings
- Gifts
- Vendor Dashboard
- Returns Center
- Saved Alerts
- Operations Center
- Seller Quality

Because the Laravel backend is mandatory, remove these residuals incrementally rather than constructing a new adapter architecture around them.

### ADOPT-005 — product media plan entry is materially stale

Status: `CORRECTION / LARGELY VERIFIED PRESENT`

The current product editor no longer uses an editable `imageUrl` business field or sends `images: [url]` in the product save payload.

Current behavior separates product business-field persistence from managed media operations and supports:

- product-specific managed upload
- reusable Media Library attach
- queued library media for newly created products
- managed detach
- alt/order update
- explicit detection of historical unmanaged media

Future work must verify lifecycle edge cases and historical URL migration, but should not reimplement this completed foundation.

### ADOPT-006 — seller-logo persistence is stable on the backend but frontend contract is transitional

Status: `PARTIAL`

Current backend behavior:

- persists `logoMediaAssetId` in vendor metadata
- resolves delivery URL from the media asset at response time
- validates seller/global media scope
- rejects cross-vendor private media
- supports temporary URL-picker compatibility by resolving the URL back to an allowed asset

Current frontend behavior in Seller Settings still stores the selected picker `item.url` in form state and submits `logoUrl` rather than using `logoMediaAssetId` directly.

Required completion:

- make Seller Settings retain both display URL and stable media asset ID separately
- submit `logoMediaAssetId` as the authoritative selection
- stop depending on URL reverse-resolution for new selections
- retain URL compatibility only for migration/backward compatibility until existing historical data is proven converged
- verify archive/reference protection remains correct

### ADOPT-007 — seller-logo backend behavior already has meaningful feature coverage

Status: `VERIFIED_PRESENT`

Existing feature tests cover:

- stable asset-reference persistence
- URL compatibility normalization to asset ID
- cross-vendor logo denial
- public URL resolution from the stored media reference
- seller media-library scoping

Do not duplicate these tests unnecessarily. Add frontend/component coverage for the picker contract when the UI is corrected.

### ADOPT-008 — frontend component-test gap remains

Status: `HARDENING / REQUIRED`

Current `package.json` includes build, lint, source audits and Playwright E2E tooling, but no normal React component/unit test stack such as Vitest + Testing Library.

Add this only as a focused P1/P4 quality layer, not as a broad test-framework rewrite.

Priority coverage:

- RBAC rendering primitives
- Media Library picker/selection contracts
- Admin design-system primitives
- form state with destructive/permission-sensitive actions
- server-error/no-fake-fallback behavior

### ADOPT-009 — demo credential risk is environment-guarded, not an exposed production-secret finding

Status: `HARDENING / VERIFY CONTINUOUSLY`

The documented predictable accounts are local/demo identities.

Current safeguards include:

- demo seeder exits when demo configuration is disabled
- production example environment explicitly disables demo seeding
- production example disables sandbox payment/shipping/SMS simulators

Keep regression coverage that makes accidental production demo seeding impossible. Do not classify the documented local password itself as a production credential unless evidence shows it is used outside the guarded demo environment.

## 5. VCS/governance baseline

Current `main` is protected by an active GitHub ruleset.

Observed protections include:

- pull-request workflow
- branch deletion protection
- non-fast-forward protection
- review-thread resolution requirement
- code-scanning/code-quality rules

Observed hardening gap:

- required approving review count is currently zero

Do not change governance automatically. Treat stronger independent review as a policy decision, especially before production release.

## 6. Revised P0–P5 interpretation

Do not replace the existing P0–P5 plan. Reconcile it as follows.

### P0 — Truth baseline and release blockers

Current state: `IN_PROGRESS`

- P0-CI-BASELINE: completed with recorded accepted evidence
- P0-01 lockfile: completed; stale blocker language should be corrected
- P0-02 license contradiction: `BLOCKED`, owner/legal decision required
- P0-03 static-audit false-confidence concern: retain; behavior tests remain authoritative

P0 exit remains blocked by P0-02.

### P1 — Server-authoritative residual cleanup

Treat as targeted residual cleanup, not a full frontend migration.

Recommended work packages:

#### P1-WP1 — Remove dead backend branching from Search

- remove static catalog/category imports from `Search.jsx`
- remove unreachable legacy search computation
- preserve API search, filters, pagination, recent searches and suggestions
- add/retain failure-state coverage proving no demo products appear on API failure

Classification: `PARALLEL_SAFE` after P0 policy blocker is cleared or explicitly allowed as independent remediation.

#### P1-WP2 — Remove dead legacy branches from Product

- remove static catalog/demo review/demo image dependencies that exist only for non-Laravel mode
- remove `useStore` business fallback paths
- preserve current API product, wallet, games, gifts, reviews, wishlist and alert contracts
- keep public visual design unchanged
- verify product API failure renders explicit error/loading/empty behavior rather than fixture content

Classification: `COORDINATED_PARALLEL` because Product touches several shared customer contracts.

#### P1-WP3 — Split `Systems.jsx` cleanup by domain

Do not delete all legacy code in one giant diff.

Suggested sequence:

1. Orders + Tracking
2. Wallet + Notifications + Messages
3. Settings + Alerts
4. Gifts + Returns
5. Checkout
6. Vendor/Operations/Seller Quality legacy remnants

For each slice:

- remove only the proven unreachable legacy path
- verify the Laravel path first
- add targeted regression coverage
- run frontend build + affected PHP/Playwright gates

Classification: `SERIALIZE` within `Systems.jsx` to avoid conflicting edits.

#### P1-WP4 — Retire global StoreProvider only after consumer proof

- inventory remaining imports/usages
- remove StoreProvider from `main.jsx` only after no production component requires it
- delete `platform/store.jsx` only after usage search plus tests prove replacement coverage
- delete/move `data/catalog.js` only after all runtime imports are removed

Classification: `SERIALIZE / FINAL P1 CLEANUP`.

### P2 — Media architecture completion

Current state is more advanced than the original plan indicates.

Priorities:

1. convert Seller Settings UI to submit stable logo asset ID directly
2. audit archive/delete reference protection for active logo/product usage
3. classify remaining `*Url` fields by intentional external URL vs managed media
4. handle historical unmanaged product media through explicit migration/convergence
5. retain existing managed product-media architecture

Do not rebuild the media library.

### P3 — Functional/admin domain repair

Keep existing plan. Prioritize behavioral/API defects over visual work.

Do not treat page presence as CRUD completeness.

### P4 — Untitled UI Admin conversion

Not yet evidenced as completed by current dependencies/source structure.

Preserve storefront CSS/visual identity. Scope design-system adoption to Admin first. Do not globally introduce resets that can change storefront behavior.

### P5 — Certification and cleanup

Retain as the final broad gate after P1–P4.

No production-readiness claim should be made from historical milestone prose alone.

## 7. Small-batch critical path after license decision

Recommended execution order:

`P0-02 decision`

→ `license metadata reconciliation + focused checks`

→ `plan/state reconciliation`

→ `P1-WP1 Search legacy removal`

→ `P1-WP2 Product legacy removal`

→ `P1-WP3 Systems slices`

→ `P1-WP4 StoreProvider/catalog retirement`

→ `P2 seller-logo frontend stable-ID contract`

→ `P2 remaining managed-media audit`

→ `P3 admin functional verification`

→ `P4 Admin design-system migration`

→ `P5 certification`

This order is intentionally conservative about shared customer surfaces while eliminating known duplicate authority before large UI work.

## 8. Testing strategy for upcoming residual cleanup

Use two-speed gates.

### Fast gate per small batch

As applicable:

- changed-file lint
- frontend source audit
- relevant PHPUnit feature tests
- targeted Playwright spec
- production frontend build
- relevant static banned-pattern check

### Full gate at milestone boundary

As applicable:

- static/build job
- Laravel static analysis
- SQLite suite
- real MySQL suite
- PostgreSQL suite
- desktop/mobile browser E2E
- accessibility/W3C gates
- runtime integration/restore/launch verification
- CodeQL/governance scans

Do not rerun flaky tests until green and call that success. Record flakes as defects.

## 9. Stop-the-line conditions

Stop affected work immediately for:

- data loss or migration corruption
- cross-user/cross-vendor data leakage
- credential/secret exposure
- unexplained destructive diff
- authorization bypass
- demo/business fallback appearing in Laravel production flow
- repository state that cannot be safely reconciled

Preserve evidence before recovery work.

## 10. Current checkpoint

State: `BLOCKED`

Current critical-path task: `P0-02`

Blocking decision: intended repository/project license.

No license choice was inferred during this audit.

Parallel-safe audit work completed:

- current main/head baseline confirmed
- existing memory/state system reused
- P0-01 stale plan finding reconciled
- server-authoritative migration progress reclassified
- residual legacy customer surfaces identified
- product-media plan status corrected
- seller-logo backend/frontend contract distinguished
- VCS ruleset posture reviewed
- post-blocker small-batch execution sequence defined

Next safe action on the critical path:

1. obtain explicit owner/legal decision: proprietary vs GPLv3 intent (and exact GPL SPDX variant if GPL is chosen)
2. reconcile `LICENSE` and `composer.json` accordingly in a focused change
3. execute focused validation/CI
4. update canonical execution state
5. begin P1 residual cleanup in the work-package order above

Do not start a broad UI rewrite while P1 duplicate-authority residuals remain unresolved.
