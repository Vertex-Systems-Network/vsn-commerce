<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the MarketplaceNotification class and its project responsibilities. */
class MarketplaceNotification extends Model
{
    protected $fillable=['public_id','user_id','category','type','title','body','action_url','reference_type','reference_id','dedup_key','data','in_app_visible','read_at'];
    /** Handles casts for the marketplace notification workflow. */
    protected function casts():array{return ['data'=>'array','in_app_visible'=>'boolean','read_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles user for the marketplace notification workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
    /** Handles deliveries for the marketplace notification workflow. */
    public function deliveries():HasMany{return $this->hasMany(NotificationDelivery::class);}
}
