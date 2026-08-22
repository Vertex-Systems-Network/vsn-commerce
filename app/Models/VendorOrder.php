<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the VendorOrder class and its project responsibilities. */
class VendorOrder extends Model
{
    use HasFactory;
    protected $fillable = ['public_id','order_id','vendor_id','status','currency','subtotal_minor','shipping_minor','discount_minor','seller_discount_minor','coupon_subsidy_minor','tax_minor','tax_included_minor','tax_added_minor','platform_tax_minor','seller_tax_minor','tax_reversed_minor','platform_tax_reversed_minor','seller_tax_reversed_minor','total_minor','commission_bps','platform_commission_minor','seller_payable_minor','refunded_minor','platform_commission_reversed_minor','seller_payable_reversed_minor','coupon_subsidy_reversed_minor','seller_recovery_offset_minor','payout_reserved_minor','paid_out_minor','finance_posted_at','packed_at','dispatched_at','delivered_at','metadata'];
    /** Handles casts for the vendor order workflow. */
    protected function casts(): array { return ['status'=>OrderStatus::class,'subtotal_minor'=>'integer','shipping_minor'=>'integer','discount_minor'=>'integer','seller_discount_minor'=>'integer','coupon_subsidy_minor'=>'integer','tax_minor'=>'integer','tax_included_minor'=>'integer','tax_added_minor'=>'integer','platform_tax_minor'=>'integer','seller_tax_minor'=>'integer','tax_reversed_minor'=>'integer','platform_tax_reversed_minor'=>'integer','seller_tax_reversed_minor'=>'integer','total_minor'=>'integer','commission_bps'=>'integer','platform_commission_minor'=>'integer','seller_payable_minor'=>'integer','refunded_minor'=>'integer','platform_commission_reversed_minor'=>'integer','seller_payable_reversed_minor'=>'integer','coupon_subsidy_reversed_minor'=>'integer','seller_recovery_offset_minor'=>'integer','payout_reserved_minor'=>'integer','paid_out_minor'=>'integer','finance_posted_at'=>'datetime','packed_at'=>'datetime','dispatched_at'=>'datetime','delivered_at'=>'datetime','metadata'=>'array']; }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles order for the vendor order workflow. */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    /** Handles vendor for the vendor order workflow. */
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    /** Handles items for the vendor order workflow. */
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    /** Updates tlement. */
    public function settlement(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(VendorSettlement::class); }
    /** Handles shipments for the vendor order workflow. */
    public function shipments(): HasMany { return $this->hasMany(Shipment::class); }
}
