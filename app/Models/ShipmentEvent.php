<?php
namespace App\Models;
use App\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the ShipmentEvent class and its project responsibilities. */
class ShipmentEvent extends Model
{
    public $timestamps=false;
    protected $fillable=['public_id','shipment_id','provider_event_id','status','code','message','location','occurred_at','payload','created_at'];
    /** Handles casts for the shipment event workflow. */
    protected function casts():array{return ['status'=>ShipmentStatus::class,'occurred_at'=>'datetime','created_at'=>'datetime','payload'=>'array'];}
    /** Handles booted for the shipment event workflow. */
    protected static function booted():void
    {
        static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Shipment events are immutable.'));
        static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Shipment events are immutable.'));
    }
    /** Handles shipment for the shipment event workflow. */
    public function shipment():BelongsTo{return $this->belongsTo(Shipment::class);}
}
