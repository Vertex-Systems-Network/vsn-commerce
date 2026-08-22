<?php
namespace App\Models;
use App\Enums\VendorPayoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the VendorPayout class and its project responsibilities. */
class VendorPayout extends Model
{
    protected $fillable=['public_id','vendor_id','vendor_payout_method_id','vendor_payout_batch_id','requested_by_user_id','approved_by_user_id','status','currency','amount_minor','payout_method_snapshot','idempotency_key','provider_reference','retry_count','failure_code','failure_message','approved_at','paid_at','failed_at','cancelled_at','metadata'];
    /** Handles casts for the vendor payout workflow. */
    protected function casts():array{return ['status'=>VendorPayoutStatus::class,'amount_minor'=>'integer','payout_method_snapshot'=>'array','retry_count'=>'integer','approved_at'=>'datetime','paid_at'=>'datetime','failed_at'=>'datetime','cancelled_at'=>'datetime','metadata'=>'array'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles vendor for the vendor payout workflow. */
    public function vendor():BelongsTo{return $this->belongsTo(Vendor::class);}
    /** Handles payout method for the vendor payout workflow. */
    public function payoutMethod():BelongsTo{return $this->belongsTo(VendorPayoutMethod::class,'vendor_payout_method_id');}
    /** Handles batch for the vendor payout workflow. */
    public function batch():BelongsTo{return $this->belongsTo(VendorPayoutBatch::class,'vendor_payout_batch_id');}
    /** Handles requested by for the vendor payout workflow. */
    public function requestedBy():BelongsTo{return $this->belongsTo(User::class,'requested_by_user_id');}
    /** Handles approved by for the vendor payout workflow. */
    public function approvedBy():BelongsTo{return $this->belongsTo(User::class,'approved_by_user_id');}
    /** Handles items for the vendor payout workflow. */
    public function items():HasMany{return $this->hasMany(VendorPayoutItem::class);}
    /** Handles attempts for the vendor payout workflow. */
    public function attempts():HasMany{return $this->hasMany(VendorPayoutAttempt::class);}
}
