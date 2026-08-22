# Android API Go-Live Checklist — Milestone AU

## Backend runtime

- Run all migrations including `2026_08_12_181000_harden_mobile_api_sessions.php`.
- Confirm HTTPS API origin and production `APP_URL`.
- Use Redis-backed rate limiting/queues where production topology requires it.
- Run Sanctum expiry/session-prune scheduler jobs.
- Run `scripts/android-api-smoke.sh` against the release candidate.

## Compatibility

- Set minimum/latest Android semantic versions.
- Set minimum Android SDK and store URL.
- Verify 400 missing/invalid version/device handling.
- Verify HTTP 426 mandatory-update UI.
- Verify optional update response header.
- Verify maintenance 503 + `Retry-After` UI.

## Mobile authentication

- Android Keystore-backed token storage verified.
- Access-token request is rejected when `X-Device-Id` does not match the bound installation.
- Refresh is synchronized; concurrent requests do not launch multiple refreshes.
- Rotated token pair is persisted atomically.
- Replay an immediately previous refresh token in staging and confirm the session becomes compromised/revoked.
- Transient refresh network failure does not erase local credentials.
- Logout, logout-all and remote session revoke tested.
- Authorization/refresh/device secrets are redacted from logs/crash analytics.
- Verify a seller/admin account authenticated with a `mobile:access` token receives 403 on `/api/v1/vendor/*` and `/api/v1/admin/*`; privileged operations remain web/session-only for this customer Android contract.

## Google / Facebook OAuth

- Provider credentials configured.
- Backend callback URLs allow-listed.
- `VSN_ANDROID_OAUTH_APP_CALLBACK_URL` is a verified production App Link.
- One-time exchange code is device-bound, single-use and expires.
- Bearer/refresh tokens never appear in browser redirect URLs.

## FCM HTTP v1

- Firebase Cloud Messaging API enabled for the project.
- `FCM_PROJECT_ID` configured.
- Service-account JSON stored outside `public/` and source control; file readable only by the app runtime identity.
- `PUT` token registration tested after login and Firebase `onNewToken` rotation.
- `DELETE` token deregistration tested.
- Android notification permission and `/api/v1/notification-preferences` push-category opt-in are tested.
- Send a staging notification and confirm all active user installations receive it once.
- Verify retry does not duplicate pushes already accepted for another installation.
- Test invalid/unregistered FCM token retirement.
- Notification payload contains no auth/payment/KYC/private-message secrets.

## Commerce E2E

Using a customer Android bearer token, verify:

- catalog/search/product detail;
- guest cart → login → merge;
- address + checkout + stock reservation;
- COD/card/coins according to enabled providers;
- payment recovery/reconciliation UI;
- order details/tracking;
- wallet/check-in/transfer;
- Game Win, gifts, wishlist and alerts;
- reviews and media upload;
- return request → tracking → refund visibility;
- messaging + attachment download;
- KYC upload/retry;
- saved payment tokenization;
- account security/device/session management.

## Contract / release evidence

- `openapi-mobile-v1.yaml` validates.
- Android Postman collection/environment import successfully.
- AU negative probes pass: mandatory update, device mismatch, refresh replay.
- Laravel automated test matrix passes in approved runtime.
- Browser E2E and production acceptance gates remain green for the same release.
