<?php
namespace App\Domain\Shipping\Actions;
use App\Domain\Shipping\Exceptions\ShippingException;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the MarkShipmentReady class and its project responsibilities. */
class MarkShipmentReady
{
    /** Executes the mark shipment ready operation. */
    public function execute(Shipment $shipment):Shipment
    {
        return DB::transaction(/** Inline callback for this operation. */ function()use($shipment):Shipment{
            $s=Shipment::query()->whereKey($shipment->id)->lockForUpdate()->with('vendorOrder')->firstOrFail();
            if($s->status->terminal()) throw new ShippingException('Terminal shipment cannot be marked ready.');
            if(!$s->provider_shipment_id) throw new ShippingException('Courier label has not been created yet.');
            if($s->status===ShipmentStatus::ReadyForPickup) return $s->load('events');
            $at=now();$s->update(['status'=>ShipmentStatus::ReadyForPickup,'ready_at'=>$s->ready_at??$at,'last_event_at'=>$at]);
            if(!$s->vendorOrder->packed_at)$s->vendorOrder->update(['status'=>OrderStatus::Packed,'packed_at'=>$at]);
            $s->events()->firstOrCreate(['provider_event_id'=>'internal:ready:'.$s->public_id],[
                'public_id'=>(string)Str::ulid(),'status'=>ShipmentStatus::ReadyForPickup,'code'=>'seller.ready','message'=>'Seller marked parcel ready for courier pickup.','occurred_at'=>$at,'payload'=>['source'=>'seller'],
            ]);
            return $s->fresh(['events','vendorOrder.vendor','items.orderItem']);
        },3);
    }
}
