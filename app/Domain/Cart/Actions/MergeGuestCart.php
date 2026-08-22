<?php

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\Services\PurchasableVariantResolver;
use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Defines the MergeGuestCart class and its project responsibilities. */
class MergeGuestCart
{
    /** Initializes the MergeGuestCart instance and its dependencies. */
    public function __construct(private readonly PurchasableVariantResolver $variants)
    {
    }

    /** Executes the merge guest cart operation. */
    public function execute(User $user, Cart $userCart, ?string $guestToken): array
    {
        if (! $guestToken) {
            return ['cart' => $userCart, 'merged' => 0, 'skipped' => 0];
        }

        return DB::transaction(/** Inline callback for this operation. */ function () use ($user, $userCart, $guestToken): array {
            $guestCart = Cart::query()
                ->where('guest_token', $guestToken)
                ->whereNull('user_id')
                ->where('status', CartStatus::Active->value)
                ->first();

            if (! $guestCart || $guestCart->id === $userCart->id) {
                return ['cart' => $userCart->fresh(), 'merged' => 0, 'skipped' => 0];
            }

            $cartIds = [$guestCart->id, $userCart->id];
            sort($cartIds);
            Cart::query()->whereIn('id', $cartIds)->orderBy('id')->lockForUpdate()->get();

            $userCart = Cart::query()
                ->whereKey($userCart->id)
                ->where('user_id', $user->id)
                ->where('status', CartStatus::Active->value)
                ->firstOrFail();

            $guestItems = CartItem::query()
                ->where('cart_id', $guestCart->id)
                ->with(['variant.product', 'variant.inventories'])
                ->lockForUpdate()
                ->get();

            $merged = 0;
            $skipped = 0;

            foreach ($guestItems as $guestItem) {
                $variant = ProductVariant::query()
                    ->with(['product', 'inventories'])
                    ->whereKey($guestItem->product_variant_id)
                    ->first();

                // If the underlying catalog row was actually removed, the line cannot be transferred.
                if (! $variant || ! $variant->product || $variant->product->currency !== $userCart->currency) {
                    $skipped += $guestItem->quantity;
                    continue;
                }

                $existing = CartItem::query()
                    ->where('cart_id', $userCart->id)
                    ->where('product_variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first();

                $existingQuantity = $existing?->quantity ?? 0;
                $capacity = max(0, 99 - $existingQuantity);
                $toAdd = min($guestItem->quantity, $capacity);

                if ($toAdd <= 0) {
                    $skipped += $guestItem->quantity;
                    continue;
                }

                $attributes = [
                    'product_id' => $variant->product_id,
                    'quantity' => $existingQuantity + $toAdd,
                    'currency' => $variant->product->currency,
                    'unit_price_minor' => $this->variants->priceMinor($variant),
                    'compare_at_price_minor' => $this->variants->compareAtPriceMinor($variant),
                    'selected_options' => $variant->option_values,
                ];

                if ($existing) {
                    $existing->update($attributes);
                } else {
                    $userCart->items()->create([
                        ...$attributes,
                        'product_variant_id' => $variant->id,
                    ]);
                }

                $merged += $toAdd;
                if ($toAdd < $guestItem->quantity) {
                    $skipped += $guestItem->quantity - $toAdd;
                }
            }

            $guestCart->update([
                'status' => CartStatus::Converted,
                'guest_token' => null,
                'metadata' => [
                    ...($guestCart->metadata ?? []),
                    'merged_into_cart_id' => $userCart->id,
                    'merged_at' => now()->toIso8601String(),
                ],
            ]);

            return [
                'cart' => $userCart->fresh(),
                'merged' => $merged,
                'skipped' => $skipped,
            ];
        }, 3);
    }
}
