<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Defines the ProductResource class and its project responsibilities.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** Handles to array for the product resource workflow. */
    public function toArray(Request $request): array
    {
        $variants = $this->whenLoaded('variants', fn () => $this->variants->map(function ($variant) {
            $stock = $variant->relationLoaded('inventories')
                ? $variant->inventories->sum(fn ($inventory) => $inventory->available())
                : null;

            return [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'options' => $variant->option_values ?? [],
                'priceMinor' => $variant->price_minor ?? $this->base_price_minor,
                'compareAtPriceMinor' => $variant->compare_at_price_minor ?? $this->compare_at_price_minor,
                'isDefault' => $variant->is_default,
                'stock' => $stock,
            ];
        }));

        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'sku' => $this->sku,
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status->value,
            'shortDescription' => $this->short_description,
            'currency' => $this->currency,
            'priceMinor' => $this->base_price_minor,
            'compareAtPriceMinor' => $this->compare_at_price_minor,
            'rating' => (float) $this->rating,
            'reviewsCount' => $this->reviews_count,
            'soldCount' => $this->sold_count,
            'installmentEnabled' => $this->installment_enabled,
            'gameEnabled' => $this->game_enabled,
            'priceIncludesTax' => $this->price_includes_tax,
            'taxClassCode' => $this->taxClass?->code,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor?->id,
                'name' => $this->vendor?->name,
                'slug' => $this->vendor?->slug,
            ]),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
                'slug' => $this->category?->slug,
            ]),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->publicUrl(),
                'alt' => $image->alt_text,
                'managed' => $image->source === 'managed',
                'mediaAssetId' => $image->relationLoaded('mediaAsset') ? $image->mediaAsset?->public_id : null,
            ])),
            'stock' => $this->relationLoaded('variants')
                ? $this->variants->sum(fn ($variant) => $variant->relationLoaded('inventories') ? $variant->inventories->sum(fn ($inventory) => $inventory->available()) : 0)
                : null,
            'inStock' => $this->relationLoaded('variants')
                ? $this->variants->contains(fn ($variant) => $variant->relationLoaded('inventories') && $variant->inventories->sum(fn ($inventory) => $inventory->available()) > 0)
                : null,
            'variants' => $variants,
            'metadata' => $this->metadata ?? new \stdClass,
        ];
    }
}
