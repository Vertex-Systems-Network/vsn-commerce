<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the PromotionCode class and its project responsibilities. */
class PromotionCode extends Model
{
    protected $fillable=['public_id','promotion_id','code','status','max_redemptions','per_user_limit'];
    /** Handles casts for the promotion code workflow. */
    protected function casts():array{return ['max_redemptions'=>'integer','per_user_limit'=>'integer'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles promotion for the promotion code workflow. */
    public function promotion():BelongsTo{return $this->belongsTo(Promotion::class);}
}
