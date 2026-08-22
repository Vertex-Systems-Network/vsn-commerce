<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the NotificationDeliveryAttempt class and its project responsibilities. */
class NotificationDeliveryAttempt extends Model
{
    public $timestamps=false;
    protected $fillable=['notification_delivery_id','attempt_number','status','provider','provider_reference','error','metadata','started_at','finished_at'];
    /** Handles casts for the notification delivery attempt workflow. */
    protected function casts():array{return ['attempt_number'=>'integer','metadata'=>'array','started_at'=>'datetime','finished_at'=>'datetime'];}
    /** Handles delivery for the notification delivery attempt workflow. */
    public function delivery():BelongsTo{return $this->belongsTo(NotificationDelivery::class,'notification_delivery_id');}
    /** Handles booted for the notification delivery attempt workflow. */
    protected static function booted():void{static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Notification delivery attempts are immutable.'));static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Notification delivery attempts are immutable.'));}
}
