<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the PromotionScope class and its project responsibilities. */
class PromotionScope extends Model
{
    protected $fillable=['promotion_id','scope_type','product_id','category_id'];
    /** Handles promotion for the promotion scope workflow. */
    public function promotion():BelongsTo{return $this->belongsTo(Promotion::class);}
    /** Handles product for the promotion scope workflow. */
    public function product():BelongsTo{return $this->belongsTo(Product::class);}
    /** Handles category for the promotion scope workflow. */
    public function category():BelongsTo{return $this->belongsTo(Category::class);}
}
