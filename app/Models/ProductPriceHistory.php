<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the ProductPriceHistory class and its project responsibilities. */
class ProductPriceHistory extends Model
{
    public $timestamps=false;
    protected $table='product_price_history';
    protected $fillable=['product_id','product_variant_id','price_minor','compare_at_price_minor','source','changed_by_user_id','metadata','recorded_at'];
    /** Handles casts for the product price history workflow. */
    protected function casts():array{return ['price_minor'=>'integer','compare_at_price_minor'=>'integer','metadata'=>'array','recorded_at'=>'datetime'];}
    /** Handles product for the product price history workflow. */
    public function product():BelongsTo{return $this->belongsTo(Product::class);}
    /** Handles variant for the product price history workflow. */
    public function variant():BelongsTo{return $this->belongsTo(ProductVariant::class,'product_variant_id');}
}
