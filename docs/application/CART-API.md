# Milestone B — Laravel Server Cart

## Invariants

1. The browser never supplies an authoritative product price or cart total.
2. A cart line always points to a concrete product variant.
3. Cart activity checks live variant inventory but does not reserve inventory.
4. Inventory is reserved only when checkout begins; this prevents abandoned carts from blocking sellable stock.
5. Guest cart ownership is represented by an opaque UUID in `X-Cart-Token`.
6. Authenticated carts are owned by `user_id`; PostgreSQL enforces at most one active cart per user.
7. Guest-to-user merge is transactional and capacity-aware.
8. Price changes are visible: `priceSnapshotMinor` preserves the last accepted price while `unitPriceMinor` and subtotal use the current server price.

## Tables

- `carts`
- `cart_items`

## React integration

`src/platform/cart.jsx` is now the cart state boundary. It stores only the opaque guest token in localStorage; actual cart lines and pricing live in Laravel/PostgreSQL.

After successful password, registration, OTP or social authentication, the frontend calls `/cart/merge`, removes the retired guest token, and replaces local cart state with the authenticated cart response.

## Next dependency

Milestone C should build checkout from the server cart, not from the static React demo state. It should create a checkout session, reserve each cart variant transactionally, calculate shipping/tax/coupon/coin adjustments, and then create the master order plus seller sub-orders.

## Product option integrity

PDP selections are resolved to a concrete `product_variants` row. The current demo seed creates color × variant/storage combinations and stores them in `option_values`. The cart API accepts `selectedOptions` and rejects a combination that does not exist instead of silently falling back to another variant.
