<?php

namespace App\Http\Resources;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Defines the CartResource class and its project responsibilities.
 *
 * @mixin Cart
 */
class CartResource extends JsonResource
{
    /** Handles to array for the cart resource workflow. */
    public function toArray(Request $request): array
    {
        $items = $this->items->map(/** Inline callback for this operation. */ function (CartItem $item): array {
            $currentPrice = $item->currentUnitPriceMinor();
            $available = $item->availableStock();
            $product = $item->product;
            $variant = $item->variant;
            $image = $product?->images?->first();
            $purchasable = $product?->status?->value === 'published' && (bool) $variant?->is_active;

            return [
                'id' => $item->id,
                'quantity' => $item->quantity,
                'currency' => $item->currency,
                'unitPriceMinor' => $currentPrice,
                'priceSnapshotMinor' => $item->unit_price_minor,
                'compareAtPriceMinor' => $item->currentCompareAtPriceMinor(),
                'lineTotalMinor' => $currentPrice * $item->quantity,
                'priceChanged' => $currentPrice !== $item->unit_price_minor,
                'stockAvailable' => $available,
                'stockIssue' => ! $purchasable || $available < $item->quantity,
                'selectedOptions' => $item->selected_options ?? new \stdClass,
                'product' => [
                    'id' => $product?->id,
                    'slug' => $product?->slug,
                    'name' => $product?->name,
                    'image' => $image?->url,
                    'vendor' => $product?->vendor?->name,
                ],
                'variant' => [
                    'id' => $variant?->id,
                    'sku' => $variant?->sku,
                    'name' => $variant?->name,
                ],
            ];
        });

        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'guestToken' => $this->user_id ? null : $this->guest_token,
            'items' => $items,
            'summary' => [
                'distinctItems' => $items->count(),
                'quantity' => $items->sum('quantity'),
                'subtotalMinor' => $items->sum('lineTotalMinor'),
                'hasStockIssues' => $items->contains(/** Inline callback for this operation. */ fn (array $item) => $item['stockIssue']),
                'hasPriceChanges' => $items->contains(/** Inline callback for this operation. */ fn (array $item) => $item['priceChanged']),
            ],
        ];
    }
}
