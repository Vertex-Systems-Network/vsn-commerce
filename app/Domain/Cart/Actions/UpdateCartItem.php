<?php

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\Exceptions\CartValidationException;
use App\Domain\Cart\Services\PurchasableVariantResolver;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/** Defines the UpdateCartItem class and its project responsibilities. */
class UpdateCartItem
{
    /** Initializes the UpdateCartItem instance and its dependencies. */
    public function __construct(private readonly PurchasableVariantResolver $variants)
    {
    }

    /** Executes the update cart item operation. */
    public function execute(Cart $cart, CartItem $item, int $quantity): Cart
    {
        if ($item->cart_id !== $cart->id) {
            throw new CartValidationException('Cart item was not found.');
        }

        return DB::transaction(/** Inline callback for this operation. */ function () use ($cart, $item, $quantity): Cart {
            $cart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $item = CartItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($quantity === 0) {
                $item->delete();
                return $cart->fresh();
            }

            $variant = ProductVariant::query()
                ->with(['product', 'inventories'])
                ->whereKey($item->product_variant_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Reuse all current publication/variant checks before changing the quantity.
            $variant = $this->variants->resolve($variant->id, null);
            $available = $this->variants->available($variant);

            if ($quantity > $available) {
                throw new CartValidationException(
                    $available > 0
                        ? "Only {$available} unit(s) are currently available."
                        : 'This item is currently out of stock.',
                    'quantity'
                );
            }

            $item->update([
                'quantity' => $quantity,
                'currency' => $variant->product->currency,
                'unit_price_minor' => $this->variants->priceMinor($variant),
                'compare_at_price_minor' => $this->variants->compareAtPriceMinor($variant),
                'selected_options' => $variant->option_values,
            ]);

            return $cart->fresh();
        }, 3);
    }
}
