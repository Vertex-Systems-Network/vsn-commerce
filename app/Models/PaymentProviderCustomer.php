<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the PaymentProviderCustomer class and its project responsibilities. */
class PaymentProviderCustomer extends Model
{
    protected $fillable=['public_id','user_id','provider','provider_customer_id_cipher'];
    protected $hidden=['provider_customer_id_cipher'];
    /** Handles casts for the payment provider customer workflow. */
    protected function casts():array{return ['provider_customer_id_cipher'=>'encrypted'];}
    /** Handles user for the payment provider customer workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
}
