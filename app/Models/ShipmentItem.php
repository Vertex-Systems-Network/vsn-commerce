<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the ShipmentItem class and its project responsibilities. */
class ShipmentItem extends Model
{
    protected $fillable=['shipment_id','order_item_id','quantity'];
    /** Handles casts for the shipment item workflow. */
    protected function casts():array{return ['quantity'=>'integer'];}
    /** Handles shipment for the shipment item workflow. */
    public function shipment():BelongsTo{return $this->belongsTo(Shipment::class);}
    /** Handles order item for the shipment item workflow. */
    public function orderItem():BelongsTo{return $this->belongsTo(OrderItem::class);}
}
