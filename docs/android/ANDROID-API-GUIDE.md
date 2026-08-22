# VSN Ecommerce Android API Guide — Milestone AU

## Architecture

The Android app uses the same Laravel commerce backend as the web app:

- `/api/mobile/v1/...` — Android authentication, compatibility, device sessions, bootstrap, OAuth exchange and FCM registration.
- `/api/v1/...` — existing customer marketplace APIs for catalog, cart, checkout, payments, orders, wallet, returns, notifications, messages, KYC, reviews, wishlist and personalization.

A Sanctum mobile bearer token has the `mobile:access` ability and can authenticate customer `/api/v1` routes. Web SPA cookie authentication remains separate.

## Required Android headers

Every Android request except the public config endpoint and provider browser OAuth callback sends:

```http
Accept: application/json
X-VSN-Client: android
X-App-Version: 1.4.2
X-Device-Id: 550e8400-e29b-41d4-a716-446655440000
```

Authenticated requests additionally send:

```http
Authorization: Bearer <access-token>
```

Generate `X-Device-Id` as a random stable installation UUID. Do not use IMEI, Android ID, advertising ID, phone number or another hardware identifier.

## Device-bound token model

- Access token: short-lived Sanctum token with `mobile:access`; default 60 minutes.
- Refresh token: independent random opaque secret; only SHA-256 hashes are stored.
- Refresh expiry: default 30 days.
- Every refresh rotates access + refresh credentials and increments `refreshGeneration`.
- Every business request made with a mobile access token is checked against the original hashed installation ID.
- The immediately previous refresh hash is retained only for replay detection. Reusing it marks the session compromised, revokes the current access credential and requires a new login.
- Logout revokes the current mobile session. Logout-all revokes all Android sessions without depending on the web session.

Store tokens with Android Keystore-backed encryption. Never place bearer/refresh secrets in plain SharedPreferences, logs, analytics, crash reports, URLs or notification payloads.

## Authentication

### Register

`POST /api/mobile/v1/auth/register`

```json
{
  "name": "Ayesha Khan",
  "email": "ayesha@example.com",
  "password": "StrongPass123",
  "password_confirmation": "StrongPass123",
  "referralCode": "VSNABC123",
  "deviceId": "550e8400-e29b-41d4-a716-446655440000",
  "deviceName": "Android Phone",
  "appVersion": "1.4.2",
  "osVersion": "Android"
}
```

### Login

`POST /api/mobile/v1/auth/login`

Successful token payload contains:

```json
{
  "tokenType": "Bearer",
  "accessToken": "1|...",
  "accessExpiresAt": "2026-08-12T14:00:00+00:00",
  "refreshToken": "...",
  "refreshExpiresAt": "2026-09-11T13:00:00+00:00",
  "sessionId": "01K...",
  "refreshGeneration": 1
}
```

### OTP / password recovery

- `POST /api/mobile/v1/auth/otp/send`
- `POST /api/mobile/v1/auth/otp/verify`
- `POST /api/mobile/v1/auth/password/forgot`
- `POST /api/mobile/v1/auth/password/reset`

### Refresh

`POST /api/mobile/v1/auth/refresh`

Send the current refresh token plus the same installation identity. Replace the stored token pair atomically after success. Never retry an old refresh credential after the app has already persisted the rotated pair.

### Google / Facebook browser OAuth

1. `GET /api/mobile/v1/auth/oauth/providers`
2. `POST /api/mobile/v1/auth/oauth/{google|facebook}/start`
3. Open the returned authorization URL in a Custom Tab.
4. Provider callback returns to Laravel.
5. Laravel redirects to the configured Android App Link with a short-lived one-time exchange code, not VSN bearer tokens.
6. `POST /api/mobile/v1/auth/oauth/exchange` exchanges that code + device context for the normal mobile token pair.

`VSN_ANDROID_OAUTH_APP_CALLBACK_URL` should be a verified HTTPS Android App Link in production. Keep provider callback URLs tied to the Laravel application's own API origin rather than a second frontend host.

## App lifecycle

- `GET /api/mobile/v1/config` — public compatibility contract.
- `GET /api/mobile/v1/bootstrap` — authenticated user/session/wallet/badges.
- `GET /api/mobile/v1/auth/me`
- `POST /api/mobile/v1/auth/logout`
- `POST /api/mobile/v1/auth/logout-all`
- `GET /api/mobile/v1/sessions`
- `DELETE /api/mobile/v1/sessions/{sessionId}`
- `PUT /api/mobile/v1/device/push-token`
- `DELETE /api/mobile/v1/device/push-token`

Session responses expose status metadata only; refresh-token hashes and FCM registration tokens are never serialized.

## Version / maintenance enforcement

Configure:

```dotenv
VSN_ANDROID_MINIMUM_VERSION=1.0.0
VSN_ANDROID_LATEST_VERSION=1.0.0
VSN_ANDROID_MINIMUM_SDK=26
VSN_ANDROID_STORE_URL=
VSN_MOBILE_MAINTENANCE_ENABLED=false
VSN_MOBILE_MAINTENANCE_RETRY_AFTER_SECONDS=300
```

Android version names must use semantic version format such as `1.4.2`.

- Missing version → `400 app_version_required`
- Invalid version syntax → `400 app_version_invalid`
- Missing installation ID → `400 device_id_required`
- Below minimum → `426 app_update_required`
- Maintenance → `503 maintenance` with `Retry-After`
- A supported-but-old client receives `X-VSN-App-Update-Available: 1` when a newer version is configured.

`/api/mobile/v1/config` remains available so an outdated/maintenance-blocked client can render recovery UI.

## FCM HTTP v1 push delivery

Registration lifecycle:

- Android Firebase SDK gives the client a registration token.
- App calls `PUT /api/mobile/v1/device/push-token` whenever the token is created or rotated.
- Token is encrypted at rest; only a SHA-256 fingerprint is used for uniqueness checks.
- `push_token_updated_at` records freshness evidence.
- Explicit deregistration calls `DELETE /api/mobile/v1/device/push-token`.
- After Android notification permission is granted, update `/api/v1/notification-preferences` for the categories where the user wants push delivery; critical security evidence keeps its server-enforced minimum channels.

Backend configuration:

```dotenv
FCM_PROJECT_ID=
FCM_SERVICE_ACCOUNT_PATH=storage/app/private/firebase-service-account.json
VSN_ANDROID_NOTIFICATION_CHANNEL=vsn_general
VSN_FCM_TIMEOUT_SECONDS=10
```

Keep the service-account JSON outside `public/` and source control. The backend creates a short-lived OAuth 2.0 access token from the service account and sends through FCM HTTP v1.

Push delivery behavior:

- one marketplace notification can fan out to all active Android installations for that user;
- successful per-device sends are checkpointed so a retry does not resend to devices already accepted by FCM;
- an FCM `UNREGISTERED` / registration-specific invalid-token response retires that registration token;
- transient provider failures remain in the notification retry queue;
- notification payload contains only notification ID/category/action URL plus display title/body, not payment/KYC/auth secrets.

## Customer commerce API

After login, use the same bearer token on `/api/v1`.

High-frequency flow:

```text
GET products
→ cart
→ checkout/options
→ checkout/session reservation
→ payment (when required)
→ order
→ order tracking
→ return/refund when eligible
```

Important endpoint families:

- Catalog/search: `/products`, `/categories`, `/search/*`, `/recommendations`, `/deals`
- Cart: `/cart*`
- Checkout: `/checkout/*`
- Payments: `/payments/*`
- Orders/tracking/invoices: `/orders*`, shipping/tracking fields returned with orders, customer invoices
- Wallet/VSN Coins: `/wallet*`
- Returns: `/returns*`
- Profile/addresses/KYC/security: `/profile`, `/addresses*`, `/kyc*`, `/security*`
- Saved payments: `/payment-methods*`
- Notifications/preferences: `/notifications*`, `/notification-preferences`
- Messaging: `/messages/*`
- Wishlist/personalization/product alerts: `/wishlist*`, `/recently-viewed`, `/buy-again`, `/product-alerts*`
- Reviews: `/reviews*`
- Affiliate/Game/Gifts: corresponding customer `/api/v1` routes

`docs/android/CUSTOMER-ENDPOINTS.md` and `routes/api.php` provide the current route inventory. Admin/vendor operational APIs are not part of the customer Android contract. The backend also enforces this boundary: a Sanctum token with the `mobile:access` ability receives HTTP 403 for `/api/v1/admin/*` and `/api/v1/vendor/*`, even when the account itself has a staff or seller role. Privileged web panels continue to use their normal web/session authorization path.

## Error envelope

Mobile middleware/framework errors use:

```json
{
  "error": {
    "code": "mobile_device_mismatch",
    "message": "This access token belongs to a different Android installation.",
    "requestId": "..."
  }
}
```

Important mobile codes include:

- `validation_error`
- `unauthenticated`
- `mobile_token_required`
- `mobile_session_revoked`
- `mobile_device_mismatch`
- `device_id_required`
- `app_version_required`
- `app_version_invalid`
- `app_update_required`
- `maintenance`
- `rate_limited`

Domain endpoints created before the normalized mobile envelope may still expose their established compatible business error shape. Clients should parse based on HTTP status first and preserve unknown JSON fields for forward compatibility.

## Retry rules

Safe automatic retries:

- GET requests;
- one synchronized access-token refresh;
- mutations that explicitly use the same idempotency key;
- notification/provider retry handled server-side.

Do not blindly replay a non-idempotent checkout/payment/order POST after a timeout unless its endpoint contract supports a stable idempotency key or you first reconcile current server state.

## Android security rules

- HTTPS only in production; reject cleartext transport.
- Keep access/refresh credentials in Keystore-backed encrypted storage.
- Keep the refresh Retrofit/OkHttp client free of `TokenAuthenticator` to prevent recursive refresh.
- A transient refresh network failure does not prove credentials are invalid; do not clear them just because the network is unavailable.
- Treat `401/422` from refresh as server rejection and require sign-in again.
- Treat `426` as mandatory update.
- Respect `Retry-After` on `429` and maintenance responses.
- Do not log Authorization headers, refresh tokens, FCM tokens, payment client secrets, private-message attachments or KYC payloads.
