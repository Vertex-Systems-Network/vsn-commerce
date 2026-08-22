<?php

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\Exceptions\CartValidationException;
use App\Domain\Cart\Services\PurchasableVariantResolver;
use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/** Defines the AddCartItem class and its project responsibilities. */
class AddCartItem
{
    /** Initializes the AddCartItem instance and its dependencies. */
    public function __construct(private readonly PurchasableVariantResolver $variants)
    {
    }

    /** Executes the add cart item operation. */
    public function execute(
        Cart $cart,
        ?int $variantId,
        ?int $productId,
        int $quantity,
        ?string $selectedVariant = null,
        ?string $productSlug = null,
        ?array $selectedOptions = null,
    ): Cart {
        $variant = $this->variants->resolve($variantId, $productId, $selectedVariant, $productSlug, $selectedOptions);

        return DB::transaction(/** Inline callback for this operation. */ function () use ($cart, $variant, $quantity): Cart {
            $cart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

            if ($cart->status !== CartStatus::Active) {
                throw new CartValidationException('This cart is no longer active.');
            }

            if ($cart->currency !== $variant->product->currency) {
                throw new CartValidationException('Products with different currencies cannot share one cart.', 'currency');
            }

            $variant = ProductVariant::query()
                ->with(['product', 'inventories'])
                ->whereKey($variant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            $desired = ($existing?->quantity ?? 0) + $quantity;
            $available = $this->variants->available($variant);

            if ($desired > 99) {
                throw new CartValidationException('A cart item cannot exceed 99 units.', 'quantity');
            }

            if ($available < $desired) {
                throw new CartValidationException(
                    $available > 0
                        ? "Only {$available} unit(s) are currently available."
                        : 'This item is currently out of stock.',
                    'quantity'
                );
            }

            $attributes = [
                'product_id' => $variant->product_id,
                'quantity' => $desired,
                'currency' => $variant->product->currency,
                'unit_price_minor' => $this->variants->priceMinor($variant),
                'compare_at_price_minor' => $this->variants->compareAtPriceMinor($variant),
                'selected_options' => $variant->option_values,
            ];

            if ($existing) {
                $existing->update($attributes);
            } else {
                $cart->items()->create([
                    ...$attributes,
                    'product_variant_id' => $variant->id,
                ]);
            }

            return $cart->fresh();
        }, 3);
    }
}
