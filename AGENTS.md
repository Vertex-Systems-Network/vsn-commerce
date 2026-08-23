# VSN Ecommerce — AI Project Master Plan

> This file is the operating contract for any AI/code agent working in this repository. Read it before changing code. Do not treat README milestone claims as proof that a feature works. Verify code paths, runtime behavior, database behavior, and tests before marking anything complete.

## 1. Project identity

- Repository: `Vertex-Systems-Network/vsn-commerce`
- Application: **VSN Ecommerce**
- Architecture: one deployable Laravel + React application, not separate frontend/backend deployments.
- Backend: Laravel 13 / PHP 8.3+, Sanctum, Socialite, Redis/Predis support.
- Frontend: React 19, React Router, Vite, Sass today.
- Databases: MySQL/MariaDB are primary local targets; PostgreSQL compatibility also exists in the repository.
- Public/customer storefront, customer account area, Seller Center, Admin Panel, and APIs are served from the same Laravel application.
- Android/mobile API support also exists, but it is not the priority of the current remediation program unless a change affects shared API contracts.

### Current remediation objective

The current program is **not** a new ecommerce rewrite. It is a stabilization and completion pass with two major goals:

1. Make the application genuinely server-authoritative and dynamic, removing legacy/static/demo behavior that can leak into real flows.
2. Rebuild the **Admin Panel UI only** using the Untitled UI React design system/component approach while preserving customer storefront visual design and existing business/API contracts unless they are defective.

## 2. Non-negotiable scope rules

### Customer-facing frontend

- **Do not redesign the public storefront/customer-facing visual UI as part of the Untitled UI migration.**
- Functional fixes to storefront data loading, API integration, media resolution, authentication, checkout, routing, validation, and error states are allowed and required where necessary.
- Do not replace working customer visual styles with Untitled UI merely for consistency with Admin.

### Admin Panel

- All `/admin/*` pages are in scope for a progressive Untitled UI conversion.
- Preserve existing URLs, RBAC permissions, business semantics, and API endpoint contracts unless an endpoint itself is proven defective.
- The target is a coherent design system, not a CSS reskin.

### Seller Center

- Seller Center is not part of the main Untitled UI redesign unless a shared primitive must be changed for correctness.
- Seller functional defects, especially media/reference/data-authority defects, are in scope.

### Data authority

- Laravel/database APIs are the production source of truth.
- Production behavior must never silently fall back to demo/localStorage/static catalog business state when an API fails.
- Demo fixtures may exist only behind an explicit development/test-only boundary and must be impossible to activate accidentally in production.

## 3. Status vocabulary every AI must use

When auditing or updating this file, classify work as:

- **VERIFIED_PRESENT** — implementation is present in code and its key contract has been directly inspected.
- **PARTIAL** — implementation exists but has legacy paths, incomplete integration, shallow tests, or inconsistent persistence.
- **UNVERIFIED** — documentation/code claims it exists, but this audit has not proven runtime correctness.
- **REQUIRED** — missing or defective work that must be completed.
- **BLOCKED** — cannot be completed until a specific dependency/decision/environment requirement is resolved.

Never call something “done” because a file exists or a string-level audit passes.

## 4. Audit baseline

Audit date: **2026-08-22**

Base branch at audit start: `main`

Base commit inspected: `fd9238a97cf3a4ab6d5f641a7b764de2c2055d32`

The repository had essentially just been imported into GitHub at the time of this audit. Treat Git history as a weak source of architectural intent; inspect current code directly.

## 5. What is already present

The following is present in the repository, but runtime status still needs to be proven by the final verification matrix.

### Core architecture — VERIFIED_PRESENT

- Unified Laravel + React repository.
- Laravel API routes under `/api/v1` and mobile API routes.
- React routing with separate storefront/account/vendor/admin shells.
- Role and permission route guards.
- Public product/category/vendor/search/deal endpoints.
- Auth, password reset, profile/security, cart, checkout, orders, payments, returns, notifications, messages, reviews, tax, promotions, finance, shipping, seller operations, admin operations, analytics, compliance/risk, and other domain APIs are represented in code.

### Admin surface — VERIFIED_PRESENT but UI is legacy/custom

Admin navigation currently includes:

- Overview
- Users
- Access Control
- Vendors
- Catalog
- Orders
- Shipping
- Payments
- Returns & Refunds
- Finance
- Payouts
- Promotions
- VSN Coins & Affiliate
- Game Win
- Tax & VAT
- Reviews
- Media
- Compliance
- Risk
- Analytics
- Seller Quality
- Notifications
- Settings
- Operations
- Readiness
- Acceptance

The current shell and pages use custom Sass/classes and a custom `Toolkit.jsx` component layer. This is the admin UI migration surface.

### Media library foundation — VERIFIED_PRESENT

There is a real reusable media-library backend with a `MediaLibraryAsset` model and seller/global ownership concepts. It stores properties including:

- public ID
- vendor owner
- uploader
- storage disk/path
- original name
- alt text
- MIME type
- byte size
- SHA-256
- dimensions
- visibility/status/metadata

There is also a reusable React `MediaLibraryPanel` supporting search, scoped admin/vendor browsing, upload, archive, and selection.

### Testing/audit infrastructure — VERIFIED_PRESENT, effectiveness varies

The repository contains:

- PHPUnit configurations
- MySQL/PostgreSQL-specific test configuration
- Playwright E2E configuration
- multiple custom PHP/Node audit scripts
- zero-to-end and runtime/release documentation

These are useful, but several custom audits are static/string-presence gates and must not be used as substitutes for behavior tests.

## 6. Confirmed audit findings

### P0-01 — `composer.lock` is missing while production docs require it — REQUIRED / RELEASE BLOCKER

The root contains `composer.json` but no `composer.lock`. The repository's own README says production remains blocked until a real reviewed `composer.lock` exists.

Required outcome:

- Generate `composer.lock` with the intended supported PHP/Composer environment.
- Review dependency resolution.
- Commit the lockfile.
- CI and production installs must use deterministic locked dependencies.
- Do not declare production readiness before this is resolved.

### P0-02 — repository license metadata is contradictory — REQUIRED

- Root `LICENSE` is GNU GPL v3.
- `composer.json` declares the package license as `proprietary`.

This must be resolved intentionally. Do not guess which license is desired. Until resolved, treat this as a release/legal metadata blocker, not a cosmetic warning.

### P0-03 — existing “marketplace audit” can produce false confidence — REQUIRED

`scripts/audit-marketplace-media-storefront.php` checks many features through string presence. For example, seeing `<MediaLibraryPanel` can satisfy a media integration check even when legacy URL persistence still exists in the same editor.

Required outcome:

- Keep useful structural/static audits, but add semantic/behavioral tests.
- Add explicit regression checks for banned production patterns described below.
- Never report a feature green solely from this script.

### P1-01 — hardcoded catalog remains in the application — REQUIRED

`resources/js/data/catalog.js` contains hardcoded categories, products, vendors, stock, prices, Unsplash URLs, game eligibility, and announcement times.

This is acceptable only as isolated development fixture data. It is not acceptable as a production data source or silent runtime fallback.

Required outcome:

- Identify every import/use of this catalog.
- Production storefront/search/product/game/review/order flows must use API data.
- Move true demo data to an explicitly named development/test fixture boundary or remove it.
- Add a production build/audit rule that rejects runtime imports of demo catalog data from live application routes.

### P1-02 — legacy localStorage business engine coexists with Laravel — REQUIRED

`resources/js/platform/store.jsx` still provides client-side/localStorage state and business rules for multiple domains, including examples such as:

- coins/check-in
- game entries
- review/coupon creation
- return requests
- product alerts
- inventory reservation
- feature flags
- gift state
- messages
- shipping quotes
- seller/finance placeholders

It imports static product/game catalog data. Some values are hardcoded, including loyalty thresholds, shipping options/prices, and an inventory availability constant.

This creates two sources of truth.

Required outcome:

- Build an inventory of every `useStore()` consumer.
- For production/Laravel mode, migrate each business capability to its API-backed implementation.
- Client localStorage may hold harmless UI preferences, but not authoritative balances, orders, inventory, returns, coupons, shipping prices, financial state, or business eligibility.
- If legacy demo mode is retained, isolate it behind an explicit `DEV/TEST ONLY` adapter and fail closed in production.
- API failure must show an error/retry state; it must not switch the user to fake data.

### P1-03 — legacy fallback branches remain in customer systems — REQUIRED

Large customer system pages still branch between Laravel and `Legacy*` implementations depending on `apiBackend`, and the module imports both the static catalog and `useStore`.

Required outcome:

- Define production API mode as mandatory.
- Remove or development-gate legacy flows.
- Add E2E tests proving API failure shows an error rather than legacy/demo content.

### P1-04 — product media integration is only partial — REQUIRED

The product editor correctly includes reusable media-library selection and managed product upload endpoints, but legacy external URL persistence remains:

- form state still contains `imageUrl`
- existing unmanaged images can repopulate that value
- save payload still sends `images: [url]`

This leaves two media models active at once.

Required outcome:

- Remove `imageUrl` from the production product editor state and payload.
- Product media must be created/attached through managed media records/media-library assets only.
- Define ordering, primary image, alt text, detach behavior, and asset lifecycle explicitly.
- If old URL-only data exists, write a one-time migration/import strategy rather than keeping permanent dual behavior.
- Test create, edit, attach, reorder, detach, archive protection, seller ownership, admin cross-seller rules, and storefront rendering.

### P1-05 — seller store logo is selected from Media Library but persisted as a URL string — REQUIRED

Seller settings use the media picker, but `onSelect` stores `item.url`, and the backend validates/persists `logoUrl` in vendor metadata.

This defeats part of the reusable media asset model: references cannot be reliably tracked if storage URLs change, assets are moved, or usage rules are added.

Required outcome:

- Store a media asset reference (prefer a proper FK where practical, otherwise a stable media public ID with enforced validation).
- API should resolve the current URL for presentation.
- Prevent cross-vendor asset references.
- Prevent archiving/deleting an asset that is still actively referenced unless replacement/removal is explicit.
- Migrate existing `logoUrl` values safely.

### P1-06 — audit all remaining image fields, not only products/logo — REQUIRED

Search every admin/seller/customer content editor for:

- `*Url`
- image URL text fields
- banner URL fields
- avatar/logo fields
- direct remote image persistence
- external Unsplash/demo image references

Classify each as:

1. external URL is intentionally allowed business data, or
2. it should use Media Library.

For type 2, replace URL input with media asset selection and stable references.

### P1-07 — settings being editable does not prove settings are operational — REQUIRED

Admin settings are database-backed and editable for groups such as Store, Orders/Returns, Catalog, and Operations. That is useful, but every setting must be traced to actual runtime consumers.

Required outcome:

For every setting key, document and test:

- where it is read
- what behavior it changes
- cache invalidation behavior
- validation
- default behavior
- permission required to edit

Remove dead settings or wire them into real behavior. Do not keep “settings theatre”.

### P1-08 — very large multi-domain React files create regression risk — REQUIRED REFACTOR

Examples include very large `Systems.jsx`, `SellerCenter.jsx`, `AdminOperations.jsx`, plus a large global `styles.scss`.

Required outcome:

- Do not rewrite everything at once.
- Split by domain/page while migrating admin UI.
- Extract shared data hooks/services and presentational components.
- Keep business operations out of giant render files.
- Prefer one domain folder with page/components/hooks/tests over new mega-files.

### P1-09 — frontend component-test layer is weak/missing — REQUIRED

The frontend has build/E2E infrastructure, but `package.json` does not currently show a normal React component/unit test stack such as Vitest + Testing Library.

Required outcome:

- Add a lightweight component/unit test layer appropriate for Vite/React.
- Prioritize Admin design-system primitives, complex form state, RBAC rendering, media picker behavior, and data adapters.
- Keep Playwright for cross-page/role/browser contracts.

### P1-10 — public demo credentials require strict environment protection — REQUIRED VERIFY

The repository documents development demo accounts/passwords. This may be acceptable for local seeds, but production must make it impossible to enable/create those accounts accidentally.

Required outcome:

- Verify production seeding guards.
- Verify demo-account endpoints are disabled in production.
- Ensure no real secrets exist in tracked files.
- Add regression tests/config audit for this guard.

## 7. Untitled UI admin migration strategy

### Source/compatibility note

As of this audit, Untitled UI's official React documentation describes a stack based on React 19.x, Tailwind CSS 4.x, TypeScript, and React Aria, with Vite/manual installation paths. Re-check official documentation before installing because versions can change.

Use only components/code that the project is licensed to use. Free/open-source Untitled UI components may be used under their applicable license; do not copy paid PRO source into the repository without a valid project license.

### Critical migration rule

**Do not run a global visual rewrite and do not let Tailwind resets change the customer storefront.**

The project currently has a large Sass stylesheet. Untitled UI migration must be scoped to Admin first.

Recommended structure:

```text
resources/js/
  admin/
    components/
      ui/
      layout/
      data-display/
      forms/
      feedback/
    hooks/
    pages/
    styles/
      admin.css
      theme.css
    lib/
  layout/AdminShell.*
```

The exact extension (`.jsx`/`.tsx`) may evolve, but do not force a whole-repository TypeScript rewrite as a prerequisite. If Untitled UI components are introduced as TypeScript, add TypeScript deliberately and allow incremental interop.

### Admin design-system foundation — first UI task

Before converting pages, create/admin-scope these primitives:

- Button / IconButton
- Input / TextArea / SearchInput
- Select / ComboBox
- Checkbox / Radio / Switch
- Badge / StatusBadge
- Avatar / User chip
- Dropdown / Menu
- Tooltip
- Modal / Confirm dialog
- Drawer / Sheet
- Alert / Inline status
- Toast
- Tabs
- Breadcrumbs
- PageHeader
- Card / MetricCard
- Table
- Pagination
- EmptyState
- Loading/Skeleton
- FileUpload / MediaPicker wrapper
- Date/DateRange controls where needed

Every component must have:

- keyboard accessibility
- visible focus state
- disabled/loading/error states
- consistent sizes
- dark/light behavior only if the application explicitly supports it
- no hidden business logic

### Admin shell migration

Convert `AdminShell` before individual pages.

Target behavior:

- Untitled-style application sidebar
- clear grouped navigation sections instead of one long unstructured list
- permission-aware nav preserved
- responsive drawer on smaller screens
- account/user menu
- page title/breadcrumb region
- optional command/search affordance only if useful
- notification access if currently supported
- “View storefront” preserved
- logout preserved
- current route highlighting preserved

Suggested navigation grouping:

**Overview**
- Overview
- Analytics

**Commerce**
- Catalog
- Orders
- Shipping
- Returns & Refunds
- Reviews

**Marketplace**
- Vendors
- Seller Quality
- Promotions
- Game Win
- VSN Coins & Affiliate
- Media

**Finance**
- Payments
- Finance
- Payouts
- Tax & VAT

**People & Access**
- Users
- Access Control

**Trust & Operations**
- Compliance
- Risk
- Notifications
- Operations
- Readiness
- Acceptance

**Configuration**
- Settings

Do not show a section if none of its children pass RBAC.

### Page migration order

Migrate admin pages in this sequence so the design system is tested on representative complexity early:

1. Overview/dashboard
2. Catalog list + product editor + Media Library
3. Orders list/detail + Returns
4. Vendors + Users + Access Control
5. Shipping + Payments + Finance + Payouts + Tax
6. Promotions + Reviews + Games + Loyalty/Affiliate
7. Analytics + Seller Quality + Risk + Compliance
8. Notifications + Settings
9. Operations + Readiness + Acceptance

For each page:

- keep endpoint contracts stable unless fixing a defect
- replace ad-hoc tables/forms/buttons with shared Admin primitives
- add proper pagination where backend provides it
- add loading/empty/error/retry states
- add destructive-action confirmation
- add success/error toasts
- preserve permission restrictions
- verify responsive behavior
- add at least one behavior test for high-risk actions

## 8. Phased execution plan

### P0 — Truth baseline and release blockers

Goal: establish a trustworthy baseline before broad refactoring.

Tasks:

- Resolve/commit `composer.lock`.
- Resolve LICENSE vs Composer license contradiction.
- Run clean install on supported environment.
- Run migration fresh + seed against isolated test DB.
- Run PHPUnit SQLite and real MySQL matrix where available.
- Run frontend build.
- Run existing static audits.
- Run Playwright smoke tests.
- Record every failing test/route as an issue/checklist item; do not suppress failures.
- Verify demo-account production guard.

Exit criteria:

- deterministic dependency install
- no destructive test points at non-test DB
- known failures documented
- no false “production ready” state

### P1 — Server-authoritative data cleanup

Goal: remove dual-source behavior.

Tasks:

- inventory all static catalog imports
- inventory all `useStore()` consumers
- classify localStorage keys into harmless UI preference vs forbidden business authority
- migrate live business flows to APIs
- remove silent legacy fallbacks
- verify settings are actually consumed
- add banned-pattern/static regression checks

Exit criteria:

- production app has one business-data authority: Laravel/database/API
- no fake order/inventory/finance/coin/shipping data appears after API failure

### P2 — Media architecture completion

Goal: every managed image uses stable media references.

Tasks:

- remove product `imageUrl` legacy persistence
- migrate product external URL legacy rows if any
- convert seller logo to media asset reference
- audit all remaining URL-image fields
- implement usage/reference protection
- standardize alt/order/primary image behavior
- test cross-vendor access and archive/delete rules

Exit criteria:

- media library is not merely a picker UI; it is the persistence authority for managed imagery

### P3 — Functional/admin domain repair

Goal: fix real admin behavior before/while visual migration.

Tasks:

- audit every admin page against its API endpoint
- validate CRUD completeness
- validate filters/search/pagination
- validate RBAC in both UI and backend
- validate setting effects
- validate media flows
- validate financial/destructive actions
- split mega-files by domain when touched

Exit criteria:

- each admin module has a documented happy path + key failure path that works

### P4 — Untitled UI Admin conversion

Goal: replace custom admin presentation with a coherent, accessible Untitled UI-based system.

Tasks:

- install only required dependencies
- build admin-scoped theme/components
- prevent global Tailwind/preflight regressions on storefront
- migrate AdminShell
- migrate pages in the defined order
- remove obsolete admin-only Sass after each migrated area is proven unused
- do not delete shared storefront styles accidentally

Exit criteria:

- all `/admin/*` pages use the new admin design system
- no mixed legacy/new admin controls except explicitly tracked temporary migration edges
- storefront screenshots/critical E2E show no visual regression caused by admin CSS

### P5 — Certification and cleanup

Goal: turn the new state into a repeatable quality gate.

Tasks:

- PHPUnit full suite
- real MySQL suite
- frontend unit/component suite
- Vite production build
- Playwright role flows
- admin media E2E
- admin RBAC E2E
- checkout/storefront smoke (to prove no unintended visual/function regression)
- static architecture audits
- dependency/security audit
- remove dead legacy files/imports only after proving they are unused
- update README/checklists to match reality

Exit criteria:

- all required CI gates green
- no known P0/P1 defects open
- documentation describes actual current behavior

## 9. Required regression tests to add

At minimum add tests for:

### Media

- product can attach global library asset
- seller can attach own/global allowed asset
- seller cannot attach another seller's asset
- product create can queue/attach library assets without URL field
- product update does not persist arbitrary image URL
- referenced media cannot be destroyed unsafely
- seller logo stores stable asset reference and resolves URL

### Dynamic data

- production mode cannot render static catalog after API failure
- production mode cannot mutate authoritative coins/inventory/orders in localStorage
- shipping options/prices come from backend in live mode
- feature flags used for business decisions come from server-authoritative configuration

### Admin

- permission hides navigation and endpoint access is independently denied
- search/filter submits expected query
- pagination uses server metadata
- settings save and cause an observable intended effect
- destructive operations require confirmation where appropriate

### Styling

- storefront critical pages remain visually/functionally unaffected by admin Tailwind/Untitled setup
- admin mobile sidebar keyboard/focus behavior works

## 10. Coding rules for AI agents

- Inspect before editing. Never infer a controller/model/table name when it can be checked.
- Do not add a second implementation when one already exists; consolidate.
- Do not add static fallback data to “make the screen work”. Fix the API/data flow.
- Do not swallow errors with empty catches on important business operations.
- Do not convert server validation into client-only validation.
- Do not weaken RBAC to make a page accessible.
- Do not store money as floats; preserve existing minor-unit integer conventions.
- Do not expose private seller/customer fields in public vendor APIs.
- Do not store secrets in DB settings intended for normal admin editing; environment/secret manager remains the source for secrets.
- Do not directly persist temporary/signed storage URLs when a stable media asset reference exists.
- Do not add arbitrary image URL inputs for managed marketplace content.
- Do not change migrations destructively without considering existing installations.
- Prefer additive migration + backfill + read transition + cleanup migration when changing persisted contracts.
- Do not create giant replacement files. Split touched mega-files progressively.
- Do not delete legacy code until usage search + tests prove replacement coverage.
- Avoid inline styles in migrated Admin pages unless truly dynamic; use design-system tokens/components.
- Keep accessible names/labels and keyboard behavior.
- Keep API response shape changes backward-compatible where mobile or other consumers may depend on them, or version intentionally.

## 11. Definition of “dynamic” for this project

A feature is dynamic only when:

1. Its authoritative value comes from DB/API/config appropriate to that domain.
2. Admin/seller changes persist and are reflected after reload/new session.
3. Different users/vendors see properly scoped data.
4. It does not depend on source-code arrays for runtime business values.
5. API failure produces an explicit failure state, not demo data.
6. Cache invalidation is defined when caching is used.
7. Tests prove the behavior.

Static enums/labels are acceptable when they are genuinely code-level domain constants, not operational data pretending to be configurable.

## 12. Definition of “media-gallery integrated”

A field is media-gallery integrated only when:

1. User selects/uploads a `MediaLibraryAsset` (or an attached managed product-media record derived from it).
2. Persistence stores a stable asset identity/reference, not merely the rendered URL.
3. Authorization validates ownership/scope.
4. Rendering resolves the current URL from the asset/storage layer.
5. Usage can be tracked.
6. Archive/delete cannot silently break active references.
7. Alt text/metadata lifecycle is defined.

A UI picker that writes `item.url` into a normal URL column/metadata field is **PARTIAL**, not complete.

## 13. Admin UI definition of done

An admin page is converted only when:

- it renders inside the new Admin shell
- it uses the shared admin tokens/primitives
- old page-specific presentation code is removed if no longer used
- loading/error/empty states are handled
- forms have validation feedback
- tables are responsive and usable
- permission behavior is preserved
- keyboard/focus accessibility is verified
- mobile/tablet layout works
- API behavior is tested
- customer storefront is unaffected

## 14. Progress ledger

Agents must update this section as work lands. Use PR/commit references where possible.

| Workstream | Status at audit | Required next state |
|---|---|---|
| Unified Laravel + React architecture | VERIFIED_PRESENT | Preserve |
| Auth/RBAC route structure | VERIFIED_PRESENT | Runtime/regression verify |
| Dynamic Laravel APIs | VERIFIED_PRESENT broadly | Integrate all live UI paths |
| Static demo catalog isolation | REQUIRED | Dev/test only or removed |
| localStorage business engine removal | REQUIRED | API-authoritative production |
| Media Library backend | VERIFIED_PRESENT | Preserve/extend |
| Product media persistence | PARTIAL | Asset-managed only |
| Seller logo media persistence | PARTIAL | Stable asset reference |
| Remaining image URL audit | REQUIRED | Classify + migrate managed fields |
| Admin settings storage | VERIFIED_PRESENT | Prove runtime effect per key |
| Admin custom UI | VERIFIED_PRESENT legacy | Migrate to Untitled UI |
| Admin Untitled design system | REQUIRED | Build scoped system |
| Frontend component tests | REQUIRED | Add focused unit/component layer |
| Playwright/PHPUnit infrastructure | VERIFIED_PRESENT | Run + extend behavior coverage |
| composer.lock | REQUIRED / BLOCKER | Generate, review, commit |
| License metadata consistency | REQUIRED | Resolve intentionally |
| Production readiness claim | UNVERIFIED/BLOCKED | Only after P0–P5 evidence |

## 15. First actions for the next AI

Before implementing a random feature, do these in order:

1. Read this file fully.
2. Confirm current branch/base SHA and inspect changes since the audit baseline.
3. Run/search for any already-landed fixes so work is not duplicated.
4. Start with the highest unresolved priority in the Progress ledger.
5. For a bug, reproduce or prove it from code/tests before editing.
6. Make the smallest coherent change that removes the root cause.
7. Add/upgrade a test that would have caught the defect.
8. Run the relevant narrow tests, then the broader gate.
9. Update this file's Progress ledger if the architectural state changed.
10. Report exactly what was verified, what remains, and what was not possible to verify.

## 16. Explicit anti-goals

Do not:

- redesign the public storefront just because Admin is moving to Untitled UI
- build a separate frontend/backend deployment
- reintroduce dummy products/users/orders as live fallbacks
- add more URL-only image fields for managed media
- mark features complete based only on README milestone text
- mark tests green without actually running them
- bypass failing tests by deleting/weakening assertions
- hide failures behind broad try/catch or fallback fake data
- perform a one-commit whole-app UI rewrite

## 17. Current target end state

The desired end state is a single Laravel + React ecommerce application where:

- production business state is server authoritative
- storefront/customer visual identity remains intact
- Seller Center is functionally correct and properly scoped
- all managed media is reusable and referentially safe
- Admin is rebuilt on a clean Untitled UI-based component system
- settings actually control documented behavior
- RBAC is enforced on both UI and API
- builds, migrations, seeds, PHPUnit, component tests, Playwright, and MySQL validation pass
- dependency locks and licensing metadata are coherent
- documentation reflects verified reality rather than aspirational milestones

---

When this file conflicts with an old milestone claim, verify current code/runtime and update the stale documentation. Do not preserve a defect for the sake of keeping an old “done” statement true.
