<?php

namespace App\Domain\Cart\Services;

use App\Domain\Cart\Exceptions\CartValidationException;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;

/** Defines the PurchasableVariantResolver class and its project responsibilities. */
class PurchasableVariantResolver
{
    /** Handles resolve for the purchasable variant resolver workflow. */
    public function resolve(?int $variantId, ?int $productId, ?string $selectedVariant = null, ?string $productSlug = null, ?array $selectedOptions = null): ProductVariant
    {
        if ($variantId) {
            $variant = ProductVariant::query()
                ->with(['product', 'inventories'])
                ->whereKey($variantId)
                ->first();
        } else {
            $product = Product::query()
                ->with(['variants.inventories'])
                ->when($productId, /** Inline callback for this operation. */ fn ($query) => $query->whereKey($productId))
                ->when(! $productId && $productSlug, /** Inline callback for this operation. */ fn ($query) => $query->where('slug', $productSlug))
                ->first();

            if (! $product) {
                throw new CartValidationException('Product was not found.', 'productId');
            }

            $variant = null;
            $selectedOptions = array_filter(
                $selectedOptions ?? [],
                /** Inline callback for this operation. */ fn ($value) => $value !== null && trim((string) $value) !== ''
            );

            if ($selectedOptions !== []) {
                $matches = $product->variants->filter(/** Inline callback for this operation. */ function (ProductVariant $candidate) use ($selectedOptions): bool {
                    $candidateOptions = array_filter(
                        $candidate->option_values ?? [],
                        /** Inline callback for this operation. */ fn ($value) => $value !== null && trim((string) $value) !== ''
                    );

                    // A partial option selection must never silently pick the first matching SKU.
                    if (count($candidateOptions) !== count($selectedOptions)) {
                        return false;
                    }

                    foreach ($selectedOptions as $key => $value) {
                        if (! array_key_exists($key, $candidateOptions)) {
                            return false;
                        }

                        if (mb_strtolower(trim((string) $candidateOptions[$key])) !== mb_strtolower(trim((string) $value))) {
                            return false;
                        }
                    }

                    return true;
                });

                if ($matches->count() !== 1) {
                    throw new CartValidationException('The selected product option combination is unavailable.', 'selectedOptions');
                }

                $variant = $matches->first();
            } elseif ($selectedVariant !== null && trim($selectedVariant) !== '') {
                $needle = mb_strtolower(trim($selectedVariant));
                $variant = $product->variants->first(/** Inline callback for this operation. */ function (ProductVariant $candidate) use ($needle): bool {
                    $legacyOption = $candidate->option_values['variant'] ?? null;

                    return mb_strtolower($candidate->name) === $needle
                        || ($legacyOption !== null && mb_strtolower((string) $legacyOption) === $needle);
                });

                if (! $variant) {
                    throw new CartValidationException('The selected product option no longer exists.', 'selectedVariant');
                }
            } else {
                $variant = $product->variants->firstWhere('is_default', true);
                $variant ??= $product->variants->first();
            }
        }

        if (! $variant || ! $variant->is_active) {
            throw new CartValidationException('The selected product option is unavailable.', 'variantId');
        }

        $variant->loadMissing(['product', 'inventories']);

        if (! $variant->product || $variant->product->status !== ProductStatus::Published) {
            throw new CartValidationException('This product is not available for purchase.', 'productId');
        }

        return $variant;
    }

    /** Handles available for the purchasable variant resolver workflow. */
    public function available(ProductVariant $variant): int
    {
        return (int) $variant->inventories->sum(/** Inline callback for this operation. */ fn ($inventory) => $inventory->available());
    }

    /** Handles price minor for the purchasable variant resolver workflow. */
    public function priceMinor(ProductVariant $variant): int
    {
        return (int) ($variant->price_minor ?? $variant->product->base_price_minor);
    }

    /** Handles compare at price minor for the purchasable variant resolver workflow. */
    public function compareAtPriceMinor(ProductVariant $variant): ?int
    {
        $price = $variant->compare_at_price_minor ?? $variant->product->compare_at_price_minor;

        return $price === null ? null : (int) $price;
    }
}
