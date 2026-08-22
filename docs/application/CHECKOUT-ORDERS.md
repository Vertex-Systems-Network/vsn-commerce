# Milestone C — Checkout and Orders

## API

Authenticated endpoints:

- `GET /api/v1/checkout/options?addressId={id}`
- `POST /api/v1/checkout/sessions`
- `GET /api/v1/checkout/sessions/{public_id}`
- `DELETE /api/v1/checkout/sessions/{public_id}`
- `POST /api/v1/checkout/sessions/{public_id}/order`
- `GET /api/v1/orders`
- `GET /api/v1/orders/{public_id}`

## Checkout session contract

A checkout session freezes the authoritative product/variant prices and creates one inventory reservation per cart line. It also snapshots the selected delivery address and server shipping quote.

The session expires according to `VSN_INVENTORY_RESERVATION_MINUTES` (default 15 minutes).

## Order conversion

Order creation is one database transaction:

1. lock checkout session,
2. verify session ownership/status/expiry,
3. create master order,
4. snapshot shipping address,
5. group items by seller,
6. create seller sub-orders and commission/payable snapshots,
7. create immutable order item snapshots,
8. convert inventory reservations into sale movements,
9. mark checkout converted,
10. mark cart converted.

A unique `orders.checkout_session_id` makes retries idempotent.

## Financial boundary

COD is the only enabled payment method in this milestone. Card payments, wallets, coupons and external providers intentionally fail closed or remain disabled until their own ledger/provider milestones exist.
