<?php
namespace App\Models;
use App\Enums\ProductAlertStatus;
use App\Enums\ProductAlertType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the ProductAlert class and its project responsibilities. */
class ProductAlert extends Model
{
    protected $fillable=['public_id','user_id','product_id','product_variant_id','type','scope_key','status','target_price_minor','last_observed_price_minor','last_observed_stock','last_notified_price_minor','last_notified_stock','triggered_at','last_checked_at'];
    /** Handles casts for the product alert workflow. */
    protected function casts():array{return ['type'=>ProductAlertType::class,'status'=>ProductAlertStatus::class,'target_price_minor'=>'integer','last_observed_price_minor'=>'integer','last_observed_stock'=>'integer','last_notified_price_minor'=>'integer','last_notified_stock'=>'integer','triggered_at'=>'datetime','last_checked_at'=>'datetime'];}
    /** Handles booted for the product alert workflow. */
    protected static function booted():void{static::creating(/** Inline callback for this operation. */ function(self $row):void{if(!$row->public_id)$row->public_id=(string)\Illuminate\Support\Str::ulid();});}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles user for the product alert workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
    /** Handles product for the product alert workflow. */
    public function product():BelongsTo{return $this->belongsTo(Product::class);}
    /** Handles variant for the product alert workflow. */
    public function variant():BelongsTo{return $this->belongsTo(ProductVariant::class,'product_variant_id');}
}
