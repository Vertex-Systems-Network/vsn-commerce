<?php
namespace App\Models;
use App\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the Shipment class and its project responsibilities. */
class Shipment extends Model
{
    protected $fillable=['public_id','order_id','vendor_order_id','vendor_id','created_by_user_id','provider','provider_shipment_id','tracking_number','service_code','status','idempotency_key','label_url','estimated_delivery_at','dispatch_not_before_at','dispatch_due_at','delivery_due_at','ready_at','picked_up_at','out_for_delivery_at','delivered_at','failed_at','rto_at','cancelled_at','dispatch_breached_at','delivery_breached_at','last_event_at','metadata','creation_attempts','last_creation_attempt_at','provider_status','provider_synced_at','provider_sync_error'];
    /** Handles casts for the shipment workflow. */
    protected function casts():array{return ['status'=>ShipmentStatus::class,'estimated_delivery_at'=>'datetime','dispatch_not_before_at'=>'datetime','dispatch_due_at'=>'datetime','delivery_due_at'=>'datetime','ready_at'=>'datetime','picked_up_at'=>'datetime','out_for_delivery_at'=>'datetime','delivered_at'=>'datetime','failed_at'=>'datetime','rto_at'=>'datetime','cancelled_at'=>'datetime','dispatch_breached_at'=>'datetime','delivery_breached_at'=>'datetime','last_event_at'=>'datetime','metadata'=>'array','creation_attempts'=>'integer','last_creation_attempt_at'=>'datetime','provider_synced_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles order for the shipment workflow. */
    public function order():BelongsTo{return $this->belongsTo(Order::class);}
    /** Handles vendor order for the shipment workflow. */
    public function vendorOrder():BelongsTo{return $this->belongsTo(VendorOrder::class);}
    /** Handles vendor for the shipment workflow. */
    public function vendor():BelongsTo{return $this->belongsTo(Vendor::class);}
    /** Handles created by for the shipment workflow. */
    public function createdBy():BelongsTo{return $this->belongsTo(User::class,'created_by_user_id');}
    /** Handles items for the shipment workflow. */
    public function items():HasMany{return $this->hasMany(ShipmentItem::class);}
    /** Handles events for the shipment workflow. */
    public function events():HasMany{return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('id');}
}
