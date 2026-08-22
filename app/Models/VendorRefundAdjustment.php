<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the VendorRefundAdjustment class and its project responsibilities. */
class VendorRefundAdjustment extends Model
{
    protected $fillable=['public_id','refund_id','vendor_order_id','refund_minor','seller_discount_reversal_minor','platform_commission_reversal_minor','seller_payable_reversal_minor','coupon_subsidy_reversal_minor','tax_reversal_minor','platform_tax_reversal_minor','seller_tax_reversal_minor','metadata'];
    /** Handles casts for the vendor refund adjustment workflow. */
    protected function casts(): array { return ['refund_minor'=>'integer','seller_discount_reversal_minor'=>'integer','platform_commission_reversal_minor'=>'integer','seller_payable_reversal_minor'=>'integer','coupon_subsidy_reversal_minor','tax_reversal_minor','platform_tax_reversal_minor','seller_tax_reversal_minor'=>'integer','metadata'=>'array']; }
    /** Handles refund for the vendor refund adjustment workflow. */
    public function refund(): BelongsTo { return $this->belongsTo(Refund::class); }
    /** Handles vendor order for the vendor refund adjustment workflow. */
    public function vendorOrder(): BelongsTo { return $this->belongsTo(VendorOrder::class); }
}
