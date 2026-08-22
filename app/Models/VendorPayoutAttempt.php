<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the VendorPayoutAttempt class and its project responsibilities. */
class VendorPayoutAttempt extends Model
{
    protected $fillable=['public_id','vendor_payout_id','attempt_no','status','provider','idempotency_key','provider_reference','failure_code','failure_message','initiated_by_user_id','started_at','completed_at','metadata'];
    /** Handles casts for the vendor payout attempt workflow. */
    protected function casts():array{return ['attempt_no'=>'integer','started_at'=>'datetime','completed_at'=>'datetime','metadata'=>'array'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles payout for the vendor payout attempt workflow. */
    public function payout():BelongsTo{return $this->belongsTo(VendorPayout::class,'vendor_payout_id');}
    /** Handles initiated by for the vendor payout attempt workflow. */
    public function initiatedBy():BelongsTo{return $this->belongsTo(User::class,'initiated_by_user_id');}
}
