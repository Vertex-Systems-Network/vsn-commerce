<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Defines the OrderItem class and its project responsibilities. */
class OrderItem extends Model
{
    use HasFactory;
    protected $fillable = ['order_id','vendor_order_id','checkout_session_item_id','product_id','product_variant_id','product_name','variant_name','sku','quantity','returned_quantity','refunded_quantity','currency','unit_price_minor','line_total_minor','taxable_minor','tax_minor','tax_included_minor','tax_added_minor','platform_tax_minor','seller_tax_minor','selected_options','metadata'];
    /** Handles casts for the order item workflow. */
    protected function casts(): array { return ['quantity'=>'integer','returned_quantity'=>'integer','refunded_quantity'=>'integer','unit_price_minor'=>'integer','line_total_minor'=>'integer','taxable_minor'=>'integer','tax_minor'=>'integer','tax_included_minor'=>'integer','tax_added_minor'=>'integer','platform_tax_minor'=>'integer','seller_tax_minor'=>'integer','selected_options'=>'array','metadata'=>'array']; }
    /** Handles order for the order item workflow. */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    /** Handles vendor order for the order item workflow. */
    public function vendorOrder(): BelongsTo { return $this->belongsTo(VendorOrder::class); }
    /** Handles product for the order item workflow. */
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    /** Handles variant for the order item workflow. */
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    /** Handles review for the order item workflow. */
    public function review(): HasOne { return $this->hasOne(Review::class); }
    /** Handles review reminder for the order item workflow. */
    public function reviewReminder(): HasOne { return $this->hasOne(ReviewReminder::class); }
}
