<?php
namespace App\Models;
use App\Enums\VendorSettlementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the VendorSettlement class and its project responsibilities. */
class VendorSettlement extends Model
{
    protected $fillable=['public_id','vendor_order_id','vendor_id','currency','gross_minor','customer_discount_minor','seller_discount_minor','coupon_subsidy_minor','platform_commission_minor','seller_payable_minor','seller_payable_reversed_minor','seller_recovery_offset_minor','payout_reserved_minor','paid_out_minor','status','eligible_at','available_at','metadata'];
    /** Handles casts for the vendor settlement workflow. */
    protected function casts():array{return ['gross_minor'=>'integer','customer_discount_minor'=>'integer','seller_discount_minor'=>'integer','coupon_subsidy_minor'=>'integer','platform_commission_minor'=>'integer','seller_payable_minor'=>'integer','seller_payable_reversed_minor'=>'integer','seller_recovery_offset_minor'=>'integer','payout_reserved_minor'=>'integer','paid_out_minor'=>'integer','status'=>VendorSettlementStatus::class,'eligible_at'=>'datetime','available_at'=>'datetime','metadata'=>'array'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles vendor order for the vendor settlement workflow. */
    public function vendorOrder():BelongsTo{return $this->belongsTo(VendorOrder::class);}
    /** Handles vendor for the vendor settlement workflow. */
    public function vendor():BelongsTo{return $this->belongsTo(Vendor::class);}
    /** Handles payout items for the vendor settlement workflow. */
    public function payoutItems():HasMany{return $this->hasMany(VendorPayoutItem::class);}
    /** Handles remaining payable minor for the vendor settlement workflow. */
    public function remainingPayableMinor():int{return max(0,$this->seller_payable_minor-$this->seller_payable_reversed_minor-$this->seller_recovery_offset_minor-$this->paid_out_minor);}
    /** Handles available minor for the vendor settlement workflow. */
    public function availableMinor():int{return max(0,$this->remainingPayableMinor()-$this->payout_reserved_minor);}
}
