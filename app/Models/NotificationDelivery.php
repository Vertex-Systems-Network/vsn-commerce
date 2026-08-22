<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the NotificationDelivery class and its project responsibilities. */
class NotificationDelivery extends Model
{
    protected $fillable=['marketplace_notification_id','channel','status','attempts','available_at','sent_at','last_error','metadata'];
    /** Handles casts for the notification delivery workflow. */
    protected function casts():array{return ['attempts'=>'integer','available_at'=>'datetime','sent_at'=>'datetime','metadata'=>'array'];}
    /** Handles notification for the notification delivery workflow. */
    public function notification():BelongsTo{return $this->belongsTo(MarketplaceNotification::class,'marketplace_notification_id');}
    /** Handles delivery attempts for the notification delivery workflow. */
    public function deliveryAttempts():HasMany{return $this->hasMany(NotificationDeliveryAttempt::class,'notification_delivery_id');}
}
