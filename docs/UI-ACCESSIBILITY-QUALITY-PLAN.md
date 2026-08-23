# UI, Accessibility and Interaction Quality Plan

## Goal

VSN Ecommerce must behave like a professionally designed production application: clear visual hierarchy, consistent spacing and typography, predictable interactions, keyboard accessibility, resilient loading/error states, and no silent browser/runtime failures.

This plan is additive to `AGENTS.md`. It does not authorize redesigning the customer storefront into the Admin design system.

## Standards baseline

- WCAG 2.2 Level AA is the accessibility target.
- W3C Nu HTML Checker is the machine-enforced HTML conformance gate for rendered pages.
- axe-core/Playwright is the mandatory automated accessibility regression gate for local and CI rendered pages.
- WAVE is an independent second-engine validation gate for a publicly reachable staging/production URL. WAVE API results must not be represented as passing when credentials or a public target URL are unavailable.
- Human keyboard, screen-reader and visual review remains required because no automated scanner can prove full accessibility or full interaction correctness.

## Implemented baseline — 2026-08-22

The current remediation branch now includes:

- reusable skip-navigation links and focusable page landmarks
- unique nested Account workspace landmark IDs
- a post-load accessibility stylesheet for visible focus, target sizes and selected contrast corrections
- logically grouped Admin navigation with a dynamic current-section title
- Playwright runtime failure collection for uncaught page errors, console errors, failed first-party requests and first-party HTTP 5xx responses
- safe interaction/navigation tests that click role-appropriate workspace navigation and reject unnamed controls/dead placeholder links
- desktop/mobile navigation coverage for storefront, Account, Seller and Admin shells
- axe automated WCAG scans on critical public/customer/seller/admin routes
- W3C Nu validation against rendered React HTML
- WAVE API integration with explicit evidence for PASS/FAIL/SKIP
- normal PR CI wiring for browser/runtime/axe/W3C and optional configured WAVE staging scans
- release-candidate wiring where WAVE configuration and passing WAVE results are mandatory

This is a baseline, not a claim that every screen already passes. New gates are expected to expose defects; those defects must be repaired rather than suppressing the checks.

## UI design contract

### Visual hierarchy

- One clear page-level heading per primary screen.
- Section headings follow semantic order and are visually distinct from body copy.
- Body text must remain comfortably readable; supporting text cannot become microcopy solely to fit a layout.
- Primary actions are visually dominant; secondary/destructive actions are clearly differentiated.
- Dense admin tables use consistent row height, alignment, truncation and overflow behavior.

### Spacing and layout

- Use a consistent spacing rhythm instead of one-off margins.
- Forms group related fields and keep labels/help/error text attached to their controls.
- Mobile layouts must not depend on horizontal page scrolling. Data tables may use an intentional scroll container.
- Sticky headers/sidebars must never obscure keyboard focus.
- Empty, loading and error states occupy the same layout context as successful content to avoid disruptive jumps.

### Interaction quality

- Every interactive control has an accessible name.
- Every form control has a visible or programmatic label.
- Keyboard focus is always visible and has sufficient contrast.
- Pointer targets should be at least 40px in normal application chrome; the hard WCAG 2.2 AA floor is 24x24 CSS px unless an allowed spacing exception applies.
- Icon-only controls require `aria-label` text.
- Destructive actions require confirmation unless the action is inherently reversible and low risk.
- Buttons do not masquerade as links and links do not masquerade as buttons.
- Disabled controls must communicate why the action is unavailable when that reason is not obvious.

### Motion

- `prefers-reduced-motion: reduce` disables non-essential animation/transitions.
- No essential information is communicated only by animation.

## Automated quality gates

### 1. Browser runtime gate

Every Playwright page fixture records:

- uncaught `pageerror`
- unexpected `console.error`
- same-origin failed requests
- same-origin HTTP 5xx responses

A test fails if any recorded runtime error remains at teardown.

### 2. Safe interaction/navigation gate

For public, customer, seller and admin areas:

- open primary navigation routes under the appropriate role
- verify route content renders and never falls into the 404 screen
- exercise safe menu/search/tab/navigation interactions
- verify every visible button/link has a non-empty accessible name
- reject placeholder links such as empty `href` or `href="#"`

Destructive/data-mutating buttons are not blindly crawler-clicked; their dedicated feature tests must cover their behavior and confirmation contract.

### 3. axe/WCAG automated gate

Critical rendered pages are scanned with `@axe-core/playwright` using WCAG 2.2 A/AA-relevant rules. Serious and critical violations fail CI. Known exceptions require a documented, narrowly scoped exclusion with an issue/owner and removal condition; blanket disabling is prohibited.

### 4. W3C rendered HTML gate

Critical rendered pages are serialized after React has loaded and validated with the Nu HTML Checker (`vnu-jar`). HTML conformance errors fail CI. Warnings are reviewed but do not automatically fail unless explicitly promoted.

### 5. WAVE independent gate

`scripts/wave-audit.mjs` checks a public `WAVE_BASE_URL` through the official WAVE API when `WAVE_API_KEY` is configured. Default release policy:

- WAVE errors: 0
- WAVE contrast errors: 0
- host HTTP status: 2xx/3xx
- alerts: reported for human review, not automatically treated as accessibility failures because many WAVE alerts require judgment

Required configuration:

- GitHub secret: `WAVE_API_KEY`
- GitHub repository/environment variable: `WAVE_BASE_URL` (public staging URL recommended; localhost cannot be scanned by WAVE cloud)
- optional `WAVE_ROUTES` comma-separated route list

WAVE cloud cannot evaluate a localhost-only CI server. For protected/non-public environments use the licensed WAVE stand-alone API/Testing Engine or WAVE browser extension during acceptance review.

## Coverage tiers

### Tier A — every PR

- build
- PHPUnit DB matrix
- Playwright Chromium desktop/mobile
- runtime error gate
- safe interaction/navigation gate
- axe critical-page gate
- W3C rendered HTML gate

### Tier B — release candidate

- Tier A
- Firefox/WebKit smoke
- WAVE public staging scan
- keyboard-only walkthrough
- zoom/reflow check at 200% and narrow viewport
- manual responsive review at representative desktop/tablet/mobile widths

### Tier C — final release acceptance

- WAVE report archived
- no unresolved critical/serious axe findings
- no W3C HTML errors
- no known dead navigation/click paths
- no uncaught browser console/runtime failures
- owner-approved visual QA of storefront, account, seller and admin surfaces

## UI migration sequence

1. Stabilize global focus, target-size, reduced-motion and semantic shell behavior.
2. Add automated accessibility/conformance/click gates before broad visual migration.
3. Fix customer/storefront defects without changing its established visual identity.
4. Normalize Account and Seller Center spacing/form/table patterns.
5. Build scoped Admin design tokens/primitives.
6. Migrate Admin pages progressively to the approved Untitled UI-derived system with Tailwind Preflight disabled and prefixed utilities.
7. Remove obsolete page-specific styles only after route/component usage and tests prove replacement coverage.

## Definition of UI done

A screen is not complete because it merely renders. It is complete only when:

- loading/error/empty/success states work
- all intended controls work with mouse and keyboard
- focus is visible and not obscured
- labels, names, heading order and landmarks are valid
- responsive layout is usable
- no browser runtime error is emitted
- route/API failures are handled explicitly
- relevant Playwright behavior tests pass
- axe and W3C gates pass for its coverage tier
- WAVE has been checked for release-tier public pages
- visual spacing/typography/action hierarchy has been reviewed as a system, not as isolated CSS patches
