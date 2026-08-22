<?php
namespace App\Domain\Shipping\Actions;
use App\Domain\Shipping\Data\VerifiedShippingWebhook;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the RecordShipmentEvent class and its project responsibilities. */
class RecordShipmentEvent
{
    /** Initializes the RecordShipmentEvent instance and its dependencies. */
    public function __construct(private readonly ReconcileOrderFulfillment $reconcile){}
    /** Executes the record shipment event operation. */
    public function execute(Shipment $shipment,VerifiedShippingWebhook $event):Shipment
    {
        $order=null;
        $shipment=DB::transaction(/** Inline callback for this operation. */ function()use($shipment,$event,&$order):Shipment{
            $s=Shipment::query()->whereKey($shipment->id)->lockForUpdate()->with(['vendorOrder.order'])->firstOrFail();
            $existing=$s->events()->where('provider_event_id',$event->eventId)->first();
            if($existing)return $s->load(['events','items.orderItem','vendorOrder.vendor']);
            $s->events()->create(['public_id'=>(string)Str::ulid(),'provider_event_id'=>$event->eventId,'status'=>$event->status,'code'=>$event->code,'message'=>$event->message,'location'=>$event->location,'occurred_at'=>$event->occurredAt,'payload'=>$event->payload]);
            $isLatest=!$s->last_event_at || $event->occurredAt->gte(CarbonImmutable::instance($s->last_event_at));
            $canProject=$this->canProject($s->status,$event->status);
            if($isLatest && $canProject && !$s->status->terminal()){
                $changes=['status'=>$event->status,'last_event_at'=>$event->occurredAt];
                $vo=[];
                if(in_array($event->status,[ShipmentStatus::PickedUp,ShipmentStatus::InTransit],true)){
                    $changes['picked_up_at']=$s->picked_up_at??$event->occurredAt;
                    if($s->dispatch_not_before_at && $event->occurredAt->lt($s->dispatch_not_before_at)){ $meta=$s->metadata??[];$meta['schedule_violation_at']=$event->occurredAt->toIso8601String();$changes['metadata']=$meta; }
                    if($s->dispatch_due_at && $event->occurredAt->gt($s->dispatch_due_at))$changes['dispatch_breached_at']=$s->dispatch_breached_at??$event->occurredAt;
                    $vo=['status'=>OrderStatus::Shipped,'dispatched_at'=>$s->vendorOrder->dispatched_at??$event->occurredAt];
                }elseif($event->status===ShipmentStatus::OutForDelivery){
                    $changes['out_for_delivery_at']=$event->occurredAt;
                    $vo=['status'=>OrderStatus::OutForDelivery,'dispatched_at'=>$s->vendorOrder->dispatched_at??$s->picked_up_at??$event->occurredAt];
                }elseif($event->status===ShipmentStatus::Delivered){
                    $changes['delivered_at']=$event->occurredAt;
                    if($s->delivery_due_at && $event->occurredAt->gt($s->delivery_due_at))$changes['delivery_breached_at']=$s->delivery_breached_at??$event->occurredAt;
                    $vo=['status'=>OrderStatus::Delivered,'delivered_at'=>$s->vendorOrder->delivered_at??$event->occurredAt,'dispatched_at'=>$s->vendorOrder->dispatched_at??$s->picked_up_at];
                }elseif($event->status===ShipmentStatus::DeliveryFailed){
                    $changes['failed_at']=$event->occurredAt;
                }elseif($event->status===ShipmentStatus::ReturnToOrigin){
                    $changes['rto_at']=$event->occurredAt;
                }elseif($event->status===ShipmentStatus::ReturnedToSender){
                    $changes['rto_at']=$s->rto_at??$event->occurredAt;
                    $vo=['status'=>OrderStatus::Returned];
                }elseif($event->status===ShipmentStatus::Cancelled){
                    $changes['cancelled_at']=$event->occurredAt;
                }
                $s->update($changes);if($vo)$s->vendorOrder->update($vo);
            }
            $order=$s->vendorOrder->order;
            return $s->fresh(['events','items.orderItem','vendorOrder.vendor']);
        },3);
        if($order)$this->reconcile->execute($order);
        return $shipment->fresh(['events','items.orderItem','vendorOrder.vendor']);
    }
    /** Handles can project for the record shipment event workflow. */
    private function canProject(ShipmentStatus $current,ShipmentStatus $incoming):bool
    {
        if($current===$incoming)return true;
        if($current===ShipmentStatus::DeliveryFailed && in_array($incoming,[ShipmentStatus::InTransit,ShipmentStatus::OutForDelivery,ShipmentStatus::ReturnToOrigin],true))return true;
        if($current===ShipmentStatus::ReturnToOrigin && $incoming===ShipmentStatus::ReturnedToSender)return true;
        $rank=[ShipmentStatus::Pending->value=>0,ShipmentStatus::LabelCreated->value=>1,ShipmentStatus::ReadyForPickup->value=>2,ShipmentStatus::PickedUp->value=>3,ShipmentStatus::InTransit->value=>4,ShipmentStatus::OutForDelivery->value=>5,ShipmentStatus::DeliveryFailed->value=>5,ShipmentStatus::Delivered->value=>6,ShipmentStatus::ReturnToOrigin->value=>7,ShipmentStatus::ReturnedToSender->value=>8,ShipmentStatus::Cancelled->value=>9];
        return ($rank[$incoming->value]??0)>=($rank[$current->value]??0);
    }
}

