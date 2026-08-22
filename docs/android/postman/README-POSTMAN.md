# VSN Ecommerce Android — Complete Postman Collection

This package contains an import-ready Postman collection for the customer Android API.

## Files

- `VSN-ANDROID-COMPLETE.postman_collection.json` — 198 organized API requests.
- `VSN-ANDROID.postman_environment.json` — local/staging variables.

## Import

1. Import both JSON files into Postman.
2. Select **VSN Ecommerce Android — Local/Staging** environment.
3. Set `base_url` to your Laravel API origin, e.g. `http://localhost:8000` or `http://localhost`.
4. Set `email` and `password` for an existing account, or edit the Register request and create one.
5. Run **01 — Mobile Authentication → Login + Get Tokens**. The test script automatically saves `access_token`, `refresh_token`, and `mobile_session_id`.
6. Run **02 — Catalog & Discovery → List / Search Products**. It automatically captures a product and variant ID.
7. For a normal purchase flow: Guest Cart → Login → Merge Cart → Create Address → Checkout Options → Create Checkout Session → Place Order or Create Payment Intent.

## Automatic variables

The collection automatically captures common IDs/tokens from successful responses, including:

- access/refresh tokens and mobile session IDs
- product / variant IDs
- guest cart token and cart item ID
- address, checkout, payment intent and order IDs
- shipment, invoice, game, gift and return IDs
- review-eligible order item
- wishlist item, notification, conversation and attachment IDs

## Android headers

Requests send:

- `X-VSN-Client: android`
- `X-App-Version: {{app_version}}`
- `X-Device-Id: {{device_id}}`
- `Authorization: Bearer {{access_token}}` for authenticated requests

The refresh token is only sent to the mobile refresh endpoint and is not used as a Bearer token.

## File uploads

KYC, review images and message attachments use Postman `form-data`. Select local test files in Postman before sending those requests. Do not save real identity documents in a shared Postman workspace or exported environment.

## Saved cards

The collection never asks for a PAN/CVC. For local testing use **Create Sandbox Masked Token**, then **Password Step-Up**, then **Save Provider Token**. For Stripe production use the SetupIntent request plus Stripe's Android SDK/PaymentSheet/Elements to produce a provider `pm_*` token.

## Milestone AU security probes

Folder **AU - Android API Final** includes config/bootstrap/session, FCM register/remove, mandatory-update, device-binding negative and rotated-refresh replay probes. A normal Refresh Tokens request captures `previous_refresh_token` for the replay test.

## Scope

This collection intentionally covers the **customer Android app**. Admin and seller APIs are not included, because the Android customer app should not ship privileged operational endpoints.

Milestone AZ adds the `AZ - Go-Live Stabilization` admin folder to the complete collection. Use an authorized Admin/Finance/Super Admin authentication context appropriate to the endpoint; Android `mobile:access` tokens cannot call privileged admin APIs.
