<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
/** Defines the ShipmentResource class and its project responsibilities. */
class ShipmentResource extends JsonResource
{
    /** Handles to array for the shipment resource workflow. */
    public function toArray(Request $request):array
    {
        return [
            'id'=>$this->public_id,'orderId'=>$this->order?->public_id,'vendorOrderId'=>$this->vendorOrder?->public_id,'seller'=>$this->vendor?->name??$this->vendorOrder?->vendor?->name??'Marketplace',
            'provider'=>$this->provider,'sandboxCanSimulate'=>$this->provider==='sandbox' && (bool)config('vsn.shipping.providers.sandbox.simulator_enabled') && !app()->environment('production'),'trackingNumber'=>$this->tracking_number,'providerStatus'=>$this->provider_status,'providerSyncedAt'=>$this->provider_synced_at?->toISOString(),'providerSyncError'=>$this->provider_sync_error,'creationAttempts'=>(int)$this->creation_attempts,'canCancel'=>in_array($this->status->value,['pending','label_created','ready_for_pickup'],true),'canRetryCreation'=>$this->status->value==='pending'&&!$this->provider_shipment_id,'serviceCode'=>$this->service_code,'status'=>$this->status->value,'labelUrl'=>$this->label_url,
            'estimatedDeliveryAt'=>$this->estimated_delivery_at?->toISOString(),'dispatchNotBeforeAt'=>$this->dispatch_not_before_at?->toISOString(),'dispatchDueAt'=>$this->dispatch_due_at?->toISOString(),'deliveryDueAt'=>$this->delivery_due_at?->toISOString(),
            'readyAt'=>$this->ready_at?->toISOString(),'pickedUpAt'=>$this->picked_up_at?->toISOString(),'outForDeliveryAt'=>$this->out_for_delivery_at?->toISOString(),'deliveredAt'=>$this->delivered_at?->toISOString(),
            'dispatchSlaBreached'=>(bool)$this->dispatch_breached_at,'deliverySlaBreached'=>(bool)$this->delivery_breached_at,
            'items'=>$this->whenLoaded('items',/** Inline callback for this operation. */ fn()=>$this->items->map(/** Inline callback for this operation. */ fn($i)=>['orderItemId'=>$i->order_item_id,'name'=>$i->orderItem?->product_name,'variant'=>$i->orderItem?->variant_name,'quantity'=>$i->quantity])->values()),
            'events'=>$this->whenLoaded('events',/** Inline callback for this operation. */ fn()=>$this->events->map(/** Inline callback for this operation. */ fn($e)=>['id'=>$e->public_id,'status'=>$e->status->value,'code'=>$e->code,'message'=>$e->message,'location'=>$e->location,'occurredAt'=>$e->occurred_at?->toISOString()])->values()),
        ];
    }
}
