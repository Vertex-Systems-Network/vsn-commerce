<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the Promotion class and its project responsibilities. */
class Promotion extends Model
{
    protected $fillable=['public_id','vendor_id','name','slug','kind','status','discount_type','percent_bps','fixed_minor','minimum_subtotal_minor','stacking_mode','can_stack_with_coupon','can_stack_with_review_coupon','funding_mode','platform_share_bps','priority','max_redemptions','per_user_limit','timezone','starts_at','ends_at','applies_to_gifts','metadata'];
    /** Handles casts for the promotion workflow. */
    protected function casts():array{return ['percent_bps'=>'integer','fixed_minor'=>'integer','minimum_subtotal_minor'=>'integer','can_stack_with_coupon'=>'boolean','can_stack_with_review_coupon'=>'boolean','platform_share_bps'=>'integer','priority'=>'integer','max_redemptions'=>'integer','per_user_limit'=>'integer','starts_at'=>'datetime','ends_at'=>'datetime','applies_to_gifts'=>'boolean','metadata'=>'array'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles vendor for the promotion workflow. */
    public function vendor():BelongsTo{return $this->belongsTo(Vendor::class);}
    /** Applies the s query scope. */
    public function scopes():HasMany{return $this->hasMany(PromotionScope::class);}
    /** Handles codes for the promotion workflow. */
    public function codes():HasMany{return $this->hasMany(PromotionCode::class);}
    /** Handles usages for the promotion workflow. */
    public function usages():HasMany{return $this->hasMany(PromotionUsage::class);}
    /** Handles is live for the promotion workflow. */
    public function isLive():bool{return $this->status==='active'&&(!$this->starts_at||$this->starts_at->lte(now()))&&(!$this->ends_at||$this->ends_at->gt(now()));}
}
