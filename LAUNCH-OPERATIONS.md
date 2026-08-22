# VSN Ecommerce — Launch Operations Layer

Version 0.4 adds the operational systems that sit between storefront UX and WooCommerce fulfilment.

## Added frontend flows
- Checkout stock reservation before final order confirmation.
- Dynamic delivery quote selection (standard / express / same-day demo).
- Multi-vendor sub-order visibility after checkout.
- Admin Marketplace Operations route: `/admin/operations`.
- Admin Seller Quality route: `/admin/seller-quality`.
- Finance/liability overview for seller payable, coins, affiliates, games, coupons and returns.
- Fraud/abuse signal surface and feature-flag controls.
- Seller SLA scorecards: dispatch, returns, cancellations, commission and payout hold.

## Added WordPress/WooCommerce tables
- `wp_vsn_inventory_reservations`
- `wp_vsn_vendor_order_splits`
- `wp_vsn_notification_queue`
- `wp_vsn_feature_flags`
- `wp_vsn_system_events`

## Added REST APIs
- `POST /wp-json/vsn/v1/inventory/reserve`
- `POST /wp-json/vsn/v1/inventory/release`
- `POST /wp-json/vsn/v1/shipping/quotes`
- `GET /wp-json/vsn/v1/vendor/splits`
- `GET|POST /wp-json/vsn/v1/notifications/preferences`
- `GET /wp-json/vsn/v1/admin/finance`
- `GET /wp-json/vsn/v1/admin/system-health`
- `GET|POST /wp-json/vsn/v1/admin/feature-flags`

## WooCommerce hooks
- Processing orders generate vendor splits grouped by product post author/vendor.
- Seller commission defaults to 10%; override via user meta `vsn_commission_rate`.
- Payout hold defaults to 7 days; override with `vsn_payout_hold_days`.
- Completed orders mark seller fulfilment split completed.

## External production providers still plug into these interfaces
The application intentionally does not hard-code a payment, courier, SMS, KYC or fraud vendor. Connect providers behind the existing REST/service boundaries rather than changing UI flows.
