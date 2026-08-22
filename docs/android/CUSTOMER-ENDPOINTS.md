# Android Customer API Endpoint Matrix

Base URL examples:

- Mobile lifecycle: `http://localhost/api/mobile/v1`
- Marketplace business API: `http://localhost/api/v1`

The Android bearer access token returned by `/api/mobile/v1/auth/login|register|otp/verify|refresh` is accepted by existing `auth:sanctum` customer endpoints **only when the request carries the original bound `X-Device-Id` plus a supported `X-App-Version`**.

## Mobile lifecycle

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| GET | `/api/mobile/v1/config` | Public | App version, maintenance, release, feature/bootstrap contract |
| POST | `/api/mobile/v1/auth/register` | Public | Register + issue token pair |
| POST | `/api/mobile/v1/auth/login` | Public | Password login + issue token pair |
| POST | `/api/mobile/v1/auth/otp/send` | Public | Send email OTP |
| POST | `/api/mobile/v1/auth/otp/verify` | Public | OTP login + issue token pair |
| POST | `/api/mobile/v1/auth/refresh` | Refresh token | Rotate token pair |
| POST | `/api/mobile/v1/auth/password/forgot` | Public | Password reset request |
| POST | `/api/mobile/v1/auth/password/reset` | Public | Complete password reset |
| GET | `/api/mobile/v1/auth/oauth/providers` | Public | Google/Facebook mobile OAuth availability |
| POST | `/api/mobile/v1/auth/oauth/{provider}/start` | Public | Start browser/Custom Tab OAuth |
| GET | `/api/mobile/v1/auth/oauth/{provider}/callback` | Provider browser callback | Validate provider + issue one-time app exchange code |
| POST | `/api/mobile/v1/auth/oauth/exchange` | One-time code | Exchange browser result for mobile token pair |
| GET | `/api/mobile/v1/bootstrap` | Mobile bearer | Initial signed-in app state |
| GET | `/api/mobile/v1/auth/me` | Mobile bearer | Current user/session |
| POST | `/api/mobile/v1/auth/logout` | Mobile bearer | Revoke current mobile session |
| POST | `/api/mobile/v1/auth/logout-all` | Mobile bearer | Revoke all mobile sessions |
| GET | `/api/mobile/v1/sessions` | Mobile bearer | List mobile sessions/devices |
| DELETE | `/api/mobile/v1/sessions/{id}` | Mobile bearer | Revoke one mobile session |
| PUT | `/api/mobile/v1/device/push-token` | Mobile bearer | Register/rotate FCM token |
| DELETE | `/api/mobile/v1/device/push-token` | Mobile bearer | Remove current FCM token |

## Public marketplace

| Method | Endpoint |
|---|---|
| GET | `/api/v1/products` |
| GET | `/api/v1/products/{product}` |
| GET | `/api/v1/categories` |
| GET | `/api/v1/search/suggestions` |
| GET | `/api/v1/recommendations` |
| GET | `/api/v1/deals` |
| GET | `/api/v1/products/{product}/reviews` |
| POST | `/api/v1/products/{product}/views` |
| GET | `/api/v1/games` |
| GET | `/api/v1/games/{game}` |
| GET | `/api/v1/cart` |
| POST | `/api/v1/cart/items` |
| PATCH | `/api/v1/cart/items/{item}` |
| DELETE | `/api/v1/cart/items/{item}` |
| DELETE | `/api/v1/cart` |

## Authenticated customer modules

The current Laravel API additionally exposes authenticated customer routes for these modules under `/api/v1`:

- auth/me and cart merge;
- profile and saved addresses;
- phone OTP verification;
- KYC status/submission/private document access;
- security/device controls;
- saved/tokenized payment methods;
- notifications and preferences;
- messaging/conversations/private attachments;
- wishlist and recently viewed;
- Buy Again and recommendations;
- product alerts;
- checkout, inventory reservation and order placement;
- orders and tracking;
- card/coin payment intents;
- wallet, transactions, check-in, transfer, coin purchases;
- affiliate enrollment/referrer/commissions;
- Game Win entries/history;
- product/coin gifts;
- returns/refunds/disputes;
- verified reviews/reward coupons;
- customer tax invoices.

Use the Laravel route file `routes/api.php` as the authoritative route registry. Admin/vendor endpoints are intentionally not part of the customer Android application contract.

## Milestone AK payment lifecycle

Authenticated customers can safely inspect/retry provider initialization without creating an untracked duplicate charge:

- `POST /api/v1/payments/{paymentIntent}/refresh-provider`
- `POST /api/v1/payments/{paymentIntent}/retry-initialization`

Provider refresh is observational/reconciliation evidence only. A remote `succeeded` state does **not** settle an order without the signed provider webhook; such a mismatch is moved to review.
