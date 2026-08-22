<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the VendorPayoutBatch class and its project responsibilities. */
class VendorPayoutBatch extends Model
{
    protected $fillable=['public_id','created_by_user_id','status','currency','total_minor','payout_count','provider_batch_reference','completed_at','metadata'];
    /** Handles casts for the vendor payout batch workflow. */
    protected function casts():array{return ['total_minor'=>'integer','payout_count'=>'integer','completed_at'=>'datetime','metadata'=>'array'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles created by for the vendor payout batch workflow. */
    public function createdBy():BelongsTo{return $this->belongsTo(User::class,'created_by_user_id');}
    /** Handles payouts for the vendor payout batch workflow. */
    public function payouts():HasMany{return $this->hasMany(VendorPayout::class,'vendor_payout_batch_id');}
}
