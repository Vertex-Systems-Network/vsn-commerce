<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the ProductView class and its project responsibilities. */
class ProductView extends Model { use HasFactory; protected $fillable=['user_id','visitor_hash','product_id','product_variant_id','source','metadata','viewed_at']; protected function casts():array{return ['metadata'=>'array','viewed_at'=>'datetime'];} public function user():BelongsTo{return $this->belongsTo(User::class);} public function product():BelongsTo{return $this->belongsTo(Product::class);} public function variant():BelongsTo{return $this->belongsTo(ProductVariant::class,'product_variant_id');} }
