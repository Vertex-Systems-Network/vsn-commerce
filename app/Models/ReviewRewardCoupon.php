<?php

namespace App\Models;

use App\Enums\ReviewCouponStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the ReviewRewardCoupon class and its project responsibilities. */
class ReviewRewardCoupon extends Model
{
    use HasFactory;
    protected $fillable = ['public_id','code','user_id','review_id','percent_bps','status','reserved_checkout_session_id','redeemed_order_id','issued_at','expires_at','reserved_at','redeemed_at','revoked_at','metadata'];
    /** Handles casts for the review reward coupon workflow. */
    protected function casts(): array { return ['percent_bps'=>'integer','status'=>ReviewCouponStatus::class,'issued_at'=>'datetime','expires_at'=>'datetime','reserved_at'=>'datetime','redeemed_at'=>'datetime','revoked_at'=>'datetime','metadata'=>'array']; }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles user for the review reward coupon workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles review for the review reward coupon workflow. */
    public function review(): BelongsTo { return $this->belongsTo(Review::class); }
    /** Handles reserved checkout session for the review reward coupon workflow. */
    public function reservedCheckoutSession(): BelongsTo { return $this->belongsTo(CheckoutSession::class, 'reserved_checkout_session_id'); }
    /** Handles redeemed order for the review reward coupon workflow. */
    public function redeemedOrder(): BelongsTo { return $this->belongsTo(Order::class, 'redeemed_order_id'); }
}
