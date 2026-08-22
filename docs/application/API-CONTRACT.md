# API contract — through Milestone C

Base: `/api/v1`

## Public
- `GET /health`
- `POST /auth/register`
- `POST /auth/login`
- `POST /auth/password/forgot`
- `POST /auth/otp/send`
- `POST /auth/otp/verify`
- `GET /products`
- `GET /products/{id}`

## Authenticated (`auth:sanctum`)
- `GET /auth/me`
- `POST /auth/logout`
- `GET /profile`
- `PUT /profile`
- `GET /addresses`
- `POST /addresses`
- `DELETE /addresses/{id}`
- `POST /inventory/reserve`
- `POST /inventory/reservations/{id}/release`

## Product money shape

Laravel stores prices as integer minor units:

```json
{
  "currency": "PKR",
  "priceMinor": 28999900,
  "compareAtPriceMinor": 32999900
}
```

React should format this for display. It must not submit a trusted total back to checkout later.

## Reservation request

```json
{
  "variantId": 1,
  "quantity": 2,
  "idempotencyKey": "checkout-session-abc:item-1",
  "reference": "cart:123"
}
```

The server locks the inventory row, validates available stock and increments reserved stock atomically.

## Cart — Milestone B

Cart endpoints are available to guests and authenticated users. Guest clients persist the `guestToken` returned by the API and send it as `X-Cart-Token` on subsequent cart requests. Authenticated users are resolved through Sanctum and do not need a guest token after merge.

### `GET /api/v1/cart`
Returns the current cart. If an anonymous client has no valid `X-Cart-Token`, the server creates a new guest cart and returns its `guestToken`.

The cart response contains current server prices, the price snapshot captured on the last cart mutation, live available inventory, stock/price change flags, and server subtotal.

### `POST /api/v1/cart/items`

```json
{
  "productId": 1,
  "selectedVariant": "256GB",
  "quantity": 2
}
```

`variantId` or stable `productSlug` may be supplied instead of `productId`. Price, subtotal and inventory supplied by the browser are ignored. The server resolves the published product/active variant and validates live stock.

### `PATCH /api/v1/cart/items/{item}`

```json
{ "quantity": 3 }
```

Quantity `0` removes the line. Quantities above live available inventory fail with HTTP `422`.

### `DELETE /api/v1/cart/items/{item}`
Removes one line owned by the current guest/authenticated cart.

### `DELETE /api/v1/cart`
Clears the current cart.

### `POST /api/v1/cart/merge` — authenticated

```json
{ "guestToken": "uuid-from-browser" }
```

Merges an anonymous cart into the authenticated user's active cart inside a database transaction. Duplicate variants are combined up to current available inventory and the 99-unit line limit. The old guest cart is marked `converted` and its token is retired.


## Checkout — Milestone C

Checkout routes require `auth:sanctum`.

### `GET /api/v1/checkout/options?addressId={id}`
Returns server shipping quotes for the current cart and the currently enabled payment methods. COD is enabled; card and coin payment are deliberately disabled until their provider/ledger milestones.

### `POST /api/v1/checkout/sessions`

```json
{
  "addressId": 1,
  "shippingMethod": "standard",
  "paymentMethod": "cod",
  "couponCode": null,
  "coinRedemptionMinor": 0,
  "idempotencyKey": "checkout-attempt-opaque-key"
}
```

The server re-reads current cart prices, validates stock, snapshots the address/price/shipping state, and creates one inventory reservation per cart line. A retry with the same idempotency key returns the same checkout session rather than reserving stock twice.

### `GET /api/v1/checkout/sessions/{public_id}`
Returns the checkout snapshot, reservation status and expiry. If a reserved session has expired, its inventory is released and the session becomes `expired`.

### `DELETE /api/v1/checkout/sessions/{public_id}`
Cancels a reserved checkout and releases its inventory holds.

### `POST /api/v1/checkout/sessions/{public_id}/order`
Atomically creates the master order, shipping-address snapshot, seller sub-orders and immutable order items, then converts each inventory reservation to a sale movement. Repeating the request returns the same order.

## Orders — Milestone C

- `GET /api/v1/orders`
- `GET /api/v1/orders/{public_id}`

Order responses include master totals and seller sub-orders. The browser never supplies an authoritative final total.
