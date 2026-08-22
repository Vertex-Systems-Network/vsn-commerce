<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the CartItem class and its project responsibilities. */
class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'currency',
        'unit_price_minor',
        'compare_at_price_minor',
        'selected_options',
        'metadata',
    ];

    /** Handles casts for the cart item workflow. */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'compare_at_price_minor' => 'integer',
            'selected_options' => 'array',
            'metadata' => 'array',
        ];
    }

    /** Handles cart for the cart item workflow. */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** Handles product for the cart item workflow. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Handles variant for the cart item workflow. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Handles current unit price minor for the cart item workflow. */
    public function currentUnitPriceMinor(): int
    {
        return (int) ($this->variant?->price_minor ?? $this->product?->base_price_minor ?? $this->unit_price_minor);
    }

    /** Handles current compare at price minor for the cart item workflow. */
    public function currentCompareAtPriceMinor(): ?int
    {
        $value = $this->variant?->compare_at_price_minor ?? $this->product?->compare_at_price_minor;

        return $value === null ? null : (int) $value;
    }

    /** Handles available stock for the cart item workflow. */
    public function availableStock(): int
    {
        if (! $this->variant?->relationLoaded('inventories')) {
            return 0;
        }

        return (int) $this->variant->inventories->sum(/** Inline callback for this operation. */ fn (Inventory $inventory) => $inventory->available());
    }
}
