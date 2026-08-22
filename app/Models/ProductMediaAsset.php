<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the ProductMediaAsset class and its project responsibilities. */
class ProductMediaAsset extends Model { use HasFactory; protected $fillable=['public_id','product_id','product_variant_id','uploaded_by_user_id','disk','path','original_name','alt_text','mime_type','byte_size','sha256','width','height','status','visibility','metadata','sort_order']; protected function casts():array{return ['byte_size'=>'integer','width'=>'integer','height'=>'integer','sort_order'=>'integer','metadata'=>'array'];} public function getRouteKeyName():string{return 'public_id';} public function product():BelongsTo{return $this->belongsTo(Product::class);} public function variant():BelongsTo{return $this->belongsTo(ProductVariant::class,'product_variant_id');} public function uploader():BelongsTo{return $this->belongsTo(User::class,'uploaded_by_user_id');} }
