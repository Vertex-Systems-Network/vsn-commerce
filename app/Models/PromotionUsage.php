<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the PromotionUsage class and its project responsibilities. */
class PromotionUsage extends Model
{
    protected $fillable=['public_id','promotion_id','promotion_code_id','user_id','checkout_session_id','order_id','status','discount_minor','platform_funded_minor','seller_funded_minor','reserved_at','redeemed_at','released_at','metadata'];
    /** Handles casts for the promotion usage workflow. */
    protected function casts():array{return ['discount_minor'=>'integer','platform_funded_minor'=>'integer','seller_funded_minor'=>'integer','reserved_at'=>'datetime','redeemed_at'=>'datetime','released_at'=>'datetime','metadata'=>'array'];}
    /** Handles promotion for the promotion usage workflow. */
    public function promotion():BelongsTo{return $this->belongsTo(Promotion::class);}
    /** Handles code for the promotion usage workflow. */
    public function code():BelongsTo{return $this->belongsTo(PromotionCode::class,'promotion_code_id');}
    /** Handles checkout session for the promotion usage workflow. */
    public function checkoutSession():BelongsTo{return $this->belongsTo(CheckoutSession::class);}
    /** Handles order for the promotion usage workflow. */
    public function order():BelongsTo{return $this->belongsTo(Order::class);}
}
