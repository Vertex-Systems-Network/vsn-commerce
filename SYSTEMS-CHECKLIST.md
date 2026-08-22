# VSN Ecommerce systems checklist

## 1) UX / system cleanup
- Modern storefront redesign retained.
- Game UI separated from normal commerce but integrated contextually.
- Coin conversion normalized globally: **70 coins = Rs.1**.
- Old conflicting coin-discount assumptions removed from cart.
- New system cards and admin operations center added.

## 2) Participant dashboard / current games
- Buyer Dashboard → **My Games**.
- Shows joined games, entry count, entry coin cost, announcement countdown and audit note.
- Demo `joinGame()` debits 70 coins per entry and updates current game participation.

## 3) Game-enabled product countdown
- Product list cards show live Game Win countdown for every `game: true` product with a matching game catalog record.
- Product detail shows full winner announcement countdown and current coin balance.
- Product detail entry uses the same game-entry system as dashboard.

## 4) VSN Coins
- Canonical conversion: 70 coins = Rs.1.
- Product checkout: cart can redeem available coins using the canonical conversion.
- Game entrance: 70 coins per Rs.1 entry.
- Gift: cash gift flow plus pay-with-coins flow.
- Buy coins: demo packages in Wallet; production flow documented as WooCommerce paid orders.
- Daily free coins: only through explicit **Daily Check-in** click.
- 7-day streak: extra 350 coins on every 7th consecutive day.
- Send coins: verified user recipient flow in Wallet.
- Persistent demo ledger in localStorage.
- WordPress custom ledger table + REST endpoints included.

## 5) 10-level affiliate system
- 10 levels implemented.
- Rates: L1 10%, L2 9%, L3 8%, L4 7%, L5 6%, L6 5%, L7 4%, L8 3%, L9 2.5%, L10 2%.
- Commission formula: eligible spend Rs × level rate × 70 coins.
- Frontend Referral page shows all 10 levels, members, spend and earned coins.
- Backend relation + commission tables included.
- WooCommerce paid-order hook traverses ancestors up to 10 levels.
- Refund reversal is explicitly marked as a production completion item.

## 6) Gift sender level
- Buyer Dashboard → Gift Sender Level.
- 5 progression levels: Thoughtful, Generous, Celebrator, Patron, Ambassador.
- Company reward/perk shown per level.
- Laravel mode records coin/product gifting in the idempotent Gift Sender progression ledger; WordPress mode retains demo fallback.
- Laravel gift tables, private recipient checkout, Gift Sender levels/rewards and scheduled notification reconciliation included; legacy WP gift table remains migration reference.
- Recommendation: move level/reward configuration into WP Admin before launch.

## 7) Profile / verification / compliance
- Basic info.
- Phone verification.
- Government ID verification: CNIC for Pakistan; passport/national ID model internationally.
- Saved payment-method status.
- Address proof.
- UI status module added to Profile.
- Backend verification table + REST endpoint included.
- Security note: store PSP tokens, not raw card data; minimize/encrypt identity data; legal government disclosure must be audited.

## 8) Frontend + WP/WC API flow
- Frontend API adapter: `resources/js/platform/api.js`.
- Mock mode works without backend.
- Switch to WordPress with `.env`:
  - `VITE_VSN_API_BASE=`
  - `VITE_VSN_USE_MOCK=false`
- REST namespace: `/wp-json/vsn/v1`.
- WooCommerce remains source of truth for products, price, stock and product entry.
- VSN product game metadata is exposed with WooCommerce product data.
- Existing React + react-icons retained; unnecessary UI dependencies avoided.

## 9) WordPress custom tables / REST API
Plugin: `wordpress/vsn-platform/vsn-platform.php`

Tables created:
- `wp_vsn_coin_ledger`
- `wp_vsn_coin_transfers`
- `wp_vsn_daily_checkins`
- `wp_vsn_games`
- `wp_vsn_game_entries`
- `wp_vsn_affiliate_relations`
- `wp_vsn_affiliate_commissions`
- `wp_vsn_gifts`
- `wp_vsn_verifications`
- `wp_vsn_audit_log`

REST endpoints included:
- `GET /me/summary`
- `GET /products`
- `POST /coins/checkin`
- `POST /coins/transfer`
- `POST /coins/redeem-quote`
- `POST /coins/purchase-intent`
- `GET /games`
- `POST /games/{game}/enter`
- `GET /affiliate/tree`
- `POST /gifts/record`
- `GET/POST /verification`
- `GET /admin/overview`

## 10) Admin system tracking / backend product ownership
- Admin Dashboard now contains a **System Control Center** tracking:
  - coin ledger
  - Game Win
  - 10-level affiliates
  - gift sender system
  - user verification
  - WooCommerce synchronization
- WooCommerce product edit screen gets VSN Game fields.
- Product creation/editing remains backend-owned in WooCommerce.
- Public/frontend product data can be served from WooCommerce REST + VSN metadata.

## Validation completed
- PHP plugin: `php -l` passes.
- TypeScript/TSX syntax transpile checks pass for App, NewPages, PlatformContext and API adapter.
- Full Vite dependency build could not be guaranteed in this environment because project node packages are not installed locally.

## Before production launch
Still required for a real money/regulated marketplace:
- WooCommerce coin redemption reservation/finalization engine.
- Hidden coin-package WC products and paid-order credit hook.
- Proportional refund/chargeback coin + affiliate reversal.
- Server-side winner selection and auditable draw job.
- Jurisdiction-specific legal review of paid/coin prize games.
- KYC provider integration and encrypted document storage.
- OTP/step-up verification for risky transfers.
- Rate limiting / fraud rules / idempotency on all write APIs.
- Admin CRUD for games, gift rewards and affiliate configuration.
- Real notifications, email/SMS, vendor permissions and moderation.

## RESTORED AFTER ROUTER/JSX REFACTOR
The following systems are intentionally preserved in the routed JSX build and must not be removed during UI refactors:
- Marketplace home / search / product detail / cart / checkout
- Buyer dashboard / orders / tracking
- Game Win entries and announcement countdowns
- VSN Coins: buy, spend, daily check-in, send/gift
- 10-level affiliate dashboard and commission model
- Gift Sender level progression
- Profile compliance: phone, government ID, address proof, saved addresses, saved payment methods
- Wallet / transaction history
- Notifications and buyer-seller/support messages
- Settings and Help Center
- Vendor dashboard: overview, products, orders, analytics, payouts
- Backend-only product entry route / vendor product form
- Admin system control center
- Login/register demo route
- WordPress/WooCommerce REST integration plugin and custom tables

## Product Detail System (restored and locked)
- [x] Image gallery + thumbnails + SafeImage fallback
- [x] Color/variant selection
- [x] Quantity and stock state
- [x] Full payment -> Add to Cart / Buy Now
- [x] Installment eligibility + 3/6/12/24 month plans + down payment + KYC requirement
- [x] Game Win eligibility + product announcement countdown + 70-coin entry + My Games persistence
- [x] Gift recipient + message + gift wrap + scheduled delivery + anonymous option
- [x] Gift payment by checkout or coins + Gift Sender progression recording
- [x] Seller verification, delivery, return, buyer-protection and authenticity signals
- [x] Reviews and product overview modules

Regression rule: Never replace Product.jsx with a reduced product page unless every option above remains reachable and functional.

## Review & next-order coupon system (locked requirement)
- [x] Product detail verified-purchase review form
- [x] 1–5 star rating
- [x] Review text validation
- [x] Up to 4 review image previews in frontend demo
- [x] `/reviews` router page with Pending Reviews, My Reviews and Review Coupons
- [x] Buyer dashboard Review shortcut + pending review reward card
- [x] Pending purchased products show a locked 10% coupon reward message
- [x] Review submission unlocks a 10% single-use coupon in frontend demo
- [x] Cart demonstrates applying an earned review coupon
- [x] WordPress REST pending/mine/upload/submit review endpoints
- [x] WooCommerce verified purchase/order-item eligibility check
- [x] Native WooCommerce review creation
- [x] Review images saved as WordPress media attachment IDs in comment meta
- [x] Real WooCommerce 10% coupon creation
- [x] Coupon is individual-use, one-use, per-user one-use and purchaser-email restricted
- [x] Review reward table prevents duplicate reward per purchased order item
- [x] Completed-order review invitation email
- [x] Coupon use updates review reward status to `used`

## Post-purchase trust & retention — added
- [x] Returns/refunds/disputes center
- [x] Return request persistent demo flow
- [x] WooCommerce-ready return request REST/table architecture
- [x] Price-drop alerts
- [x] Back-in-stock alerts
- [x] WooCommerce price/stock alert email hooks
- [x] Buy Again surface in buyer dashboard
- [x] Admin visibility for returns and alerts

## Launch operations added in v0.4
- [x] Inventory reservation / oversell protection architecture
- [x] Multi-vendor order split architecture
- [x] Seller commission and payout hold records
- [x] Shipping quote API flow
- [x] Notification preference API + notification queue table
- [x] Admin finance/liability endpoint
- [x] Admin system-health endpoint
- [x] Global feature flags
- [x] Seller SLA/quality UI
- [x] Marketplace operations admin UI

## Production readiness layer (v0.5.0)
- [x] Provider integration registry
- [x] Webhook event ingestion/idempotency table
- [x] Background job queue + WP-Cron worker
- [x] User session/device registry + revoke endpoint
- [x] Security event registry
- [x] Notification preference persistence table
- [x] Admin production-readiness UI + REST summary
- [x] Legal/compliance center route
- [ ] Live payment gateway credentials/provider adapter
- [ ] Live courier credentials/provider adapter
- [ ] Live SMS/OTP provider adapter
- [ ] Live KYC/liveness provider adapter
- [ ] Live transactional email provider adapter
- [ ] External search provider adapter (optional)
- [ ] Backup/CDN/analytics provider configuration
- [ ] Pen-test, accessibility, browser/device QA and restore rehearsal

## Authentication and account access (v2.2)

- Dedicated `/login` and `/register` screens.
- Email/password registration and sign-in mapped to WordPress/WooCommerce customers.
- Password recovery route and WordPress reset workflow.
- Passwordless email OTP sign-in with expiry and request throttling.
- Google OAuth/OIDC sign-in.
- Sign in with Apple server authorization flow.
- Facebook Login OAuth flow.
- Sign in with LinkedIn using OpenID Connect.
- Server-side OAuth state validation and provider code exchange.
- Social identity linking table preventing duplicate provider-subject attachment.
- Login session registration and existing device/session revoke support.
- Provider secrets kept server-side via adapter/filter, not in frontend source.
- Dedicated `/auth/callback` success/error route.


## Laravel finance & payouts — Milestone K
- [x] Immutable double-entry finance journal
- [x] PostgreSQL ledger mutation triggers + application immutability guards
- [x] Seller payable + platform commission posting
- [x] Platform-funded verified-review coupon subsidy accounting
- [x] COD receivable + explicit collection confirmation
- [x] Seller settlement payment/delivery/return-window holds
- [x] Idempotent seller payout requests
- [x] Finance payout approval + paid confirmation
- [x] Payout batch creation + batch reconciliation
- [x] Refund finance journals
- [x] Seller recovery receivable after already-paid refunds
- [x] Future earnings offset seller recovery before payout
- [x] Finance reconciliation issue registry
- [x] Live Laravel Vendor Finance Center
- [x] Live Laravel Admin Finance/Operations Center
- [ ] Live bank/payout provider adapter + signed payout callbacks
- [ ] Tax/VAT/GST accounting engine


## Laravel shipping & courier — Milestone L
- [x] Courier provider interface + manager
- [x] Dev-only signed sandbox courier
- [x] Seller pack / label / ready-for-pickup workflow
- [x] Customer-selected service protected after checkout
- [x] Unpaid online-order dispatch blocked
- [x] Shipment + shipment item persistence
- [x] Immutable carrier tracking events
- [x] Signed webhook verification + event replay protection
- [x] Out-of-order event projection regression protection
- [x] Delivery failure / RTO / returned-to-sender lifecycle
- [x] Seller-wise delivery timestamps
- [x] Master-order delivery aggregation
- [x] Courier delivery automatically reconciles seller settlement
- [x] Dispatch + delivery SLA scheduler
- [x] Scheduled gift dispatch-window-aware SLA
- [x] Customer Laravel tracking UI
- [x] Vendor Laravel fulfilment UI
- [x] Admin live seller SLA quality UI
- [ ] Live courier provider credentials/adapter
- [ ] Dynamic provider-native shipping quotes
- [ ] Multi-package partial shipment from one vendor sub-order

## Laravel notifications & messaging — Milestone M
- [x] Central marketplace notification source of truth
- [x] Per-user category/channel preferences
- [x] Deduplicated notification publishing
- [x] In-app unread/read/read-all lifecycle
- [x] Email delivery outbox + retry state
- [x] Authoritative source-event reconciliation safety net
- [x] Review reminders routed through central notification pipeline
- [x] Header notification/message unread counters
- [x] Order-scoped buyer ↔ seller conversation
- [x] Seller-to-buyer link from fulfilment center
- [x] Customer support conversation/inbox
- [x] Conversation participant authorization/isolation
- [x] Append-only message records
- [x] Client-id message idempotency
- [x] Read cursor / unread counts
- [x] Private message attachment storage/download authorization
- [x] Private broadcast channel authorization
- [x] HTTP polling fallback with Reverb-ready backend events
- [ ] Live transactional email credentials/provider
- [ ] Live SMS provider
- [ ] Live push notification provider
- [ ] Reverb + Echo production deployment (optional)
- [ ] Attachment malware scanning / PDF content-disarm pipeline
- [ ] Seller-staff-to-vendor membership mapping for messaging
- [ ] Durable source-event cursor/outbox optimization for very high notification volume


## Milestone O payment vault
- Laravel saved payment methods use provider tokens only; raw PAN/CVC storage is prohibited.
- Payment-method mutation requires device-bound password step-up.
- Live card vaulting remains blocked until a production payment provider adapter is configured.

## Milestone Q — Personalization / Product Media / Seller Analytics
- [x] Account-scoped wishlist/favorites
- [x] Recently viewed history with user clear-history control
- [x] Configurable product-view retention/pruning
- [x] Server-side personalized recommendations
- [x] Real Buy Again from historical delivered purchases
- [x] Managed product image uploads with hash/MIME/size/dimensions
- [x] Storage-disk/CDN abstraction with S3/R2-ready configuration
- [x] Vendor-scoped catalog analytics (views, conversion, units, revenue, wishlist saves)
- [x] Seller self-view/self-wishlist exclusion from analytics

## Milestone S — Tax / VAT / GST + Invoicing
- [x] Safe-off tax engine with admin-configured country/region jurisdictions
- [x] Product tax classes and seller tax profiles
- [x] Inclusive/exclusive product and shipping tax snapshots
- [x] Platform-vs-seller tax liability allocation
- [x] Immutable per-seller invoice snapshots and refund credit notes
- [x] Finance sales-tax payable posting and proportional refund reversal
- [x] Seller/admin tax management and customer invoice UI


## Milestone T — Fraud / Risk / Abuse Prevention

- [x] User risk profile + explainable score
- [x] Seller risk profile
- [x] Immutable risk evidence
- [x] Manual review cases
- [x] Scoped user/vendor holds
- [x] Multi-account device/payment/phone signals
- [x] Payment failure/mismatch signals
- [x] Wallet transfer velocity
- [x] Game Win velocity
- [x] Return velocity and return-ratio signal
- [x] Affiliate referrer-device signal + reward hold
- [x] Seller payout risk reevaluation and hold gate
- [x] Admin Risk Center
- [x] Five-minute risk reconciliation scheduler


## Milestone U — Business Intelligence / Reporting
- [x] Ledger-derived Admin Analytics Center
- [x] GMV / paid-order-value definition
- [x] Net order value + completed/manual refund separation
- [x] Platform commission / subsidy / seller payout reporting
- [x] VSN Coin / affiliate / Game Win liability reporting
- [x] Seller performance breakdown
- [x] Promotion attributed-value / discount-spend reporting
- [x] Tax collection/refund reporting
- [x] Customer repeat/cohort reporting
- [x] Private CSV export queue
- [x] SHA-256 + row count + retention metadata
- [x] CSV spreadsheet-formula injection protection
- [x] Pseudonymous customer references in exports
- [x] Finance-user export/schedule ownership isolation
- [x] Daily/weekly/monthly report schedules
- [x] Rolling 7/30-day, MTD and previous-month periods
- [x] Scheduled export ready notifications
- [ ] Production BI warehouse/read replica for very high scale
- [ ] Columnar analytics store if marketplace volume outgrows PostgreSQL OLTP reporting
- [ ] Experiment/control-group framework for causal promotion incrementality

## Milestone W — runtime / launch verification
- [x] Docker PostgreSQL + Redis integration stack
- [x] PostgreSQL Laravel feature-test runtime path
- [x] frontend production-build runtime path
- [x] stateful Sanctum API E2E smoke
- [x] queue worker + scheduler heartbeat verification
- [x] backup -> isolated restore drill
- [x] machine-readable launch verification manifest
- [x] persistent admin launch-gate audit runs
- [x] CI runtime-integration gate
- [ ] real payment/courier/SMS production adapters (external/provider-specific)
- [ ] committed backend Composer lock generated in approved package environment
