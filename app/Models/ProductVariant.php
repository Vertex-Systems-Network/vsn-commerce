<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the ProductVariant class and its project responsibilities. */
class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'option_values',
        'price_minor',
        'compare_at_price_minor',
        'is_default',
        'is_active',
    ];

    /** Handles casts for the product variant workflow. */
    protected function casts(): array
    {
        return [
            'option_values' => 'array',
            'price_minor' => 'integer',
            'compare_at_price_minor' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Handles product for the product variant workflow. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Handles inventories for the product variant workflow. */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    /** Handles cart items for the product variant workflow. */
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class, 'product_variant_id');
    }
}
