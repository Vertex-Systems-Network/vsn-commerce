<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Defines the CatalogManagementProductResource class and its project responsibilities.
 *
 * @mixin Product
 */
class CatalogManagementProductResource extends JsonResource
{
    /** Handles to array for the catalog management product resource workflow. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'dbId' => $this->id,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'name' => $this->name,
            'shortDescription' => $this->short_description,
            'description' => $this->description,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'basePriceMinor' => $this->base_price_minor,
            'compareAtPriceMinor' => $this->compare_at_price_minor,
            'installmentEnabled' => $this->installment_enabled,
            'gameEnabled' => $this->game_enabled,
            'taxClassId' => $this->taxClass?->public_id,
            'priceIncludesTax' => $this->price_includes_tax,
            'rating' => (float) $this->rating,
            'reviewsCount' => $this->reviews_count,
            'soldCount' => $this->sold_count,
            'vendor' => $this->vendor ? [
                'id' => $this->vendor->id,
                'name' => $this->vendor->name,
                'slug' => $this->vendor->slug,
            ] : null,
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null,
            'images' => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->publicUrl(),
                'alt' => $image->alt_text,
                'managed' => $image->source === 'managed',
                'mediaAssetId' => $image->mediaAsset?->public_id,
                'sortOrder' => $image->sort_order,
            ])->all(),
            'variants' => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'options' => $variant->option_values ?? [],
                'priceMinor' => $variant->price_minor,
                'compareAtPriceMinor' => $variant->compare_at_price_minor,
                'isDefault' => $variant->is_default,
                'isActive' => $variant->is_active,
                'stock' => $variant->inventories->sum(fn ($inventory) => $inventory->available()),
                'onHand' => $variant->inventories->sum('on_hand'),
                'reserved' => $variant->inventories->sum('reserved'),
                'safetyStock' => $variant->inventories->sum('safety_stock'),
            ])->all(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
