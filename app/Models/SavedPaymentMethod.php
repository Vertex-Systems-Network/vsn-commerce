<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the SavedPaymentMethod class and its project responsibilities. */
class SavedPaymentMethod extends Model
{
    protected $fillable=['public_id','user_id','provider','payment_method','provider_token_cipher','provider_customer_id_cipher','fingerprint_sha256','brand','last4','exp_month','exp_year','holder_name','billing_address_snapshot','status','is_default','verified_at','last_used_at','revoked_at','metadata'];
    protected $hidden=['provider_token_cipher','provider_customer_id_cipher','fingerprint_sha256','mysql_default_user_guard'];
    /** Handles casts for the saved payment method workflow. */
    protected function casts():array{return ['provider_token_cipher'=>'encrypted','provider_customer_id_cipher'=>'encrypted','billing_address_snapshot'=>'array','is_default'=>'boolean','verified_at'=>'datetime','last_used_at'=>'datetime','revoked_at'=>'datetime','metadata'=>'array'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles user for the saved payment method workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
    /** Handles checkout sessions for the saved payment method workflow. */
    public function checkoutSessions():HasMany{return $this->hasMany(CheckoutSession::class);}
    /** Handles payment intents for the saved payment method workflow. */
    public function paymentIntents():HasMany{return $this->hasMany(PaymentIntent::class);}
    /** Handles is active for the saved payment method workflow. */
    public function isActive():bool{return $this->status==='active' && $this->revoked_at===null && (!$this->exp_year || !$this->exp_month || sprintf('%04d-%02d-01',$this->exp_year,$this->exp_month) >= now()->startOfMonth()->format('Y-m-d'));}
}
