<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the WishlistItem class and its project responsibilities. */
class WishlistItem extends Model { use HasFactory; protected $fillable=['public_id','user_id','product_id','product_variant_id','scope_key']; public function getRouteKeyName():string{return 'public_id';} public function user():BelongsTo{return $this->belongsTo(User::class);} public function product():BelongsTo{return $this->belongsTo(Product::class);} public function variant():BelongsTo{return $this->belongsTo(ProductVariant::class,'product_variant_id');} }
