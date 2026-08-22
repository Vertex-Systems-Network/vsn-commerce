<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the CatalogSearchEvent class and its project responsibilities. */
class CatalogSearchEvent extends Model
{
    protected $fillable=['public_id','user_id','visitor_hash','query','normalized_query','result_count','filters','searched_at'];
    /** Handles casts for the catalog search event workflow. */
    protected function casts():array{return ['result_count'=>'integer','filters'=>'array','searched_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles user for the catalog search event workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
}
