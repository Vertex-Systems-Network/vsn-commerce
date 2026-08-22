<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the CheckoutPromotionAllocation class and its project responsibilities. */
class CheckoutPromotionAllocation extends Model
{
    /** Handles booted for the checkout promotion allocation workflow. */
    protected static function booted(): void
    {
        static::updating(/** Inline callback for this operation. */ fn () => throw new \LogicException('Checkout promotion allocations are immutable snapshots.'));
        static::deleting(/** Inline callback for this operation. */ fn () => throw new \LogicException('Checkout promotion allocations are immutable snapshots.'));
    }
    protected $fillable=['checkout_session_id','checkout_session_item_id','promotion_id','promotion_usage_id','source_type','source_reference','discount_minor','platform_funded_minor','seller_funded_minor','metadata'];
    /** Handles casts for the checkout promotion allocation workflow. */
    protected function casts():array{return ['discount_minor'=>'integer','platform_funded_minor'=>'integer','seller_funded_minor'=>'integer','metadata'=>'array'];}
    /** Handles checkout session for the checkout promotion allocation workflow. */
    public function checkoutSession():BelongsTo{return $this->belongsTo(CheckoutSession::class);}
    /** Handles item for the checkout promotion allocation workflow. */
    public function item():BelongsTo{return $this->belongsTo(CheckoutSessionItem::class,'checkout_session_item_id');}
    /** Handles promotion for the checkout promotion allocation workflow. */
    public function promotion():BelongsTo{return $this->belongsTo(Promotion::class);}
    /** Handles usage for the checkout promotion allocation workflow. */
    public function usage():BelongsTo{return $this->belongsTo(PromotionUsage::class,'promotion_usage_id');}
}
