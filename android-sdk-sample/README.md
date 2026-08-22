# VSN Ecommerce Android API sample

This folder is an integration reference, not a complete Android application.

Recommended client stack:

- Retrofit 2 + OkHttp
- Coroutines
- kotlinx.serialization or Moshi
- Firebase Messaging SDK on the Android client
- Android Keystore-backed encrypted token storage

Every Android API/business request sends:

- `X-VSN-Client: android`
- `X-App-Version: <semantic version, e.g. 1.4.2>`
- `X-Device-Id: <stable random installation UUID>`
- `Authorization: Bearer <access token>` after authentication

## Token architecture

The access token is short-lived. The refresh token rotates on every successful refresh and is bound to the original installation ID. The server also binds every mobile access-token request to that installation ID. A replay of the immediately previous refresh token revokes the affected mobile session.

Use a Keystore-backed `TokenStore`; never plain SharedPreferences. Create the refresh Retrofit instance with `AuthInterceptor` if desired for client headers, **but without `TokenAuthenticator`**, otherwise a failed refresh can recursively invoke itself.

`TokenAuthenticator` serializes concurrent refresh attempts. It clears local secrets only when the server actually rejects the refresh credential (401/422), not for a temporary network failure.

## FCM

Call `PUT /api/mobile/v1/device/push-token` whenever Firebase gives the app a registration token, including token rotation. Call `DELETE /api/mobile/v1/device/push-token` on explicit notification deregistration/logout if appropriate. The server encrypts the token at rest and tracks its hash/timestamp.

The backend sends through FCM HTTP v1 when `FCM_PROJECT_ID` and `FCM_SERVICE_ACCOUNT_PATH` are configured. Keep the service-account JSON outside `public/` and source control. After the user grants Android notification permission, call `/api/v1/notification-preferences` to enable the desired push categories.

## App compatibility

Always fetch `/api/mobile/v1/config` on cold start. HTTP 426 means the installed app is below the mandatory minimum; direct the user to the configured store URL. `X-VSN-App-Update-Available: 1` on normal responses indicates a non-mandatory newer app version exists.

## Commerce API

The same mobile bearer token authenticates customer endpoints under `/api/v1`, including catalog, cart, checkout, payments, orders, returns, wallet, wishlist, notifications, messages, KYC, saved payments and account security. `VSNCustomerApi` contains the high-frequency examples. A `mobile:access` bearer is intentionally rejected with HTTP 403 on `/api/v1/admin/*` and `/api/v1/vendor/*`; privileged operational panels are outside this customer Android token contract.

## Never log

Do not log access tokens, refresh tokens, Authorization headers, payment client secrets, KYC payloads, FCM registration tokens or full device identifiers.
