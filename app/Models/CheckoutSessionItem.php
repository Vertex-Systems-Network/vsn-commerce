<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the CheckoutSessionItem class and its project responsibilities. */
class CheckoutSessionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'checkout_session_id', 'cart_item_id', 'product_id', 'product_variant_id', 'vendor_id',
        'inventory_reservation_id', 'product_name', 'variant_name', 'sku', 'quantity', 'currency',
        'unit_price_minor', 'line_total_minor', 'selected_options', 'metadata',
    ];

    /** Handles casts for the checkout session item workflow. */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
            'selected_options' => 'array',
            'metadata' => 'array',
        ];
    }

    /** Handles checkout session for the checkout session item workflow. */
    public function checkoutSession(): BelongsTo { return $this->belongsTo(CheckoutSession::class); }
    /** Handles cart item for the checkout session item workflow. */
    public function cartItem(): BelongsTo { return $this->belongsTo(CartItem::class); }
    /** Handles product for the checkout session item workflow. */
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    /** Handles variant for the checkout session item workflow. */
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    /** Handles vendor for the checkout session item workflow. */
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    /** Handles reservation for the checkout session item workflow. */
    public function reservation(): BelongsTo { return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id'); }
}
