<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the VendorPayoutMethod class and its project responsibilities. */
class VendorPayoutMethod extends Model
{
    protected $fillable=['public_id','vendor_id','type','label','account_holder_name','bank_name','account_identifier_cipher','account_last4','routing_identifier_cipher','routing_last4','country_code','currency','is_default','verified_by_user_id','verified_at','revoked_at','metadata'];
    protected $hidden=['account_identifier_cipher','routing_identifier_cipher'];
    /** Handles casts for the vendor payout method workflow. */
    protected function casts():array{return ['account_identifier_cipher'=>'encrypted','routing_identifier_cipher'=>'encrypted','is_default'=>'boolean','verified_at'=>'datetime','revoked_at'=>'datetime','metadata'=>'array'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles vendor for the vendor payout method workflow. */
    public function vendor():BelongsTo{return $this->belongsTo(Vendor::class);}
    /** Handles verifier for the vendor payout method workflow. */
    public function verifier():BelongsTo{return $this->belongsTo(User::class,'verified_by_user_id');}
    /** Handles payouts for the vendor payout method workflow. */
    public function payouts():HasMany{return $this->hasMany(VendorPayout::class);}
}
