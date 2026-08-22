<?php
namespace App\Domain\Shipping\Actions;
use App\Domain\Shipping\Exceptions\ShippingException;
use App\Domain\Shipping\Services\ShippingProviderManager;
use App\Domain\Shipping\Services\ShippingSlaService;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\User;
use App\Models\VendorOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the CreateShipment class and its project responsibilities. */
class CreateShipment
{
    /** Initializes the CreateShipment instance and its dependencies. */
    public function __construct(private readonly ShippingProviderManager $providers,private readonly ShippingSlaService $sla){}
    /** Executes the create shipment operation. */
    public function execute(User $actor,VendorOrder $vendorOrder,string $serviceCode,string $idempotencyKey):Shipment
    {
        $existing=Shipment::query()->where('idempotency_key',$idempotencyKey)->with(['order.shippingAddress','events','items.orderItem','vendorOrder.vendor'])->first();
        if($existing){
            if($existing->vendor_order_id!==$vendorOrder->id)throw new ShippingException('Idempotency key belongs to another shipment.');
            if($existing->provider_shipment_id || $existing->status!==ShipmentStatus::Pending)return $existing;
            return $this->initializeProvider($existing);
        }
        $service=$this->sla->service($serviceCode);$providerCode=(string)($service['provider']??'sandbox');
        $shipment=DB::transaction(/** Inline callback for this operation. */ function()use($actor,$vendorOrder,$serviceCode,$providerCode,$idempotencyKey):Shipment{
            $vo=VendorOrder::query()->whereKey($vendorOrder->id)->lockForUpdate()->with(['order.shippingAddress','items'])->firstOrFail();$order=$vo->order;
            $purchasedService=(string)(($order->metadata??[])['shipping_method']??'');if($purchasedService!==''&&$purchasedService!==$serviceCode)throw new ShippingException('Shipment service must match the customer-selected checkout shipping method.');
            if($order->payment_status!==PaymentStatus::Paid&&$order->payment_method!=='cod')throw new ShippingException('Online-payment orders cannot be shipped before verified payment.');
            if(in_array($vo->status,[OrderStatus::Cancelled,OrderStatus::Returned,OrderStatus::Refunded,OrderStatus::Delivered],true))throw new ShippingException('This seller order cannot be shipped.');
            $active=Shipment::query()->where('vendor_order_id',$vo->id)->whereNotIn('status',[ShipmentStatus::Cancelled->value,ShipmentStatus::ReturnedToSender->value])->lockForUpdate()->first();if($active)return $active->load(['order.shippingAddress','events','items.orderItem','vendorOrder.vendor']);
            $giftTarget=($vo->metadata??[])['gift_delivery_target_at']??null;$deadlines=$this->sla->deadlines($serviceCode,$order->placed_at,$giftTarget);
            $shipment=Shipment::create(['public_id'=>(string)Str::ulid(),'order_id'=>$order->id,'vendor_order_id'=>$vo->id,'vendor_id'=>$vo->vendor_id,'created_by_user_id'=>$actor->id,'provider'=>$providerCode,'service_code'=>$serviceCode,'status'=>ShipmentStatus::Pending,'idempotency_key'=>$idempotencyKey,...$deadlines,'metadata'=>['gift_delivery_target_at'=>$giftTarget]]);
            foreach($vo->items as $item)$shipment->items()->create(['order_item_id'=>$item->id,'quantity'=>$item->quantity]);return $shipment->load(['order.shippingAddress','events','items.orderItem','vendorOrder.vendor']);
        },3);
        if($shipment->provider_shipment_id||$shipment->status!==ShipmentStatus::Pending)return $shipment;
        return $this->initializeProvider($shipment);
    }
    /** Handles retry provider initialization for the create shipment workflow. */
    public function retryProviderInitialization(Shipment $shipment):Shipment
    {
        $shipment->refresh();
        if($shipment->provider_shipment_id || $shipment->status!==ShipmentStatus::Pending) return $shipment->load(['events','items.orderItem','vendorOrder.vendor']);
        return $this->initializeProvider($shipment->loadMissing('order.shippingAddress'));
    }
    /** Handles initialize provider for the create shipment workflow. */
    private function initializeProvider(Shipment $shipment):Shipment
    {
        $max=max(1,(int)config('vsn.shipping.max_creation_attempts',5));if($shipment->creation_attempts>=$max)throw new ShippingException('Courier label creation retry limit reached.');
        $shipment->forceFill(['creation_attempts'=>$shipment->creation_attempts+1,'last_creation_attempt_at'=>now(),'provider_sync_error'=>null])->save();
        try{
            $result=$this->providers->driver($shipment->provider)->createShipment($shipment,$shipment->order->shippingAddress?->toArray()??[]);
            $shipment->update(['provider_shipment_id'=>$result->providerShipmentId,'tracking_number'=>$result->trackingNumber,'label_url'=>$result->labelUrl,'estimated_delivery_at'=>$result->estimatedDeliveryAt??$shipment->delivery_due_at,'status'=>ShipmentStatus::LabelCreated,'provider_status'=>ShipmentStatus::LabelCreated->value,'provider_synced_at'=>now(),'provider_sync_error'=>null,'metadata'=>array_merge($shipment->metadata??[],$result->metadata)]);
            if(!$shipment->events()->where('provider_event_id','label:'.$result->providerShipmentId)->exists())$shipment->events()->create(['public_id'=>(string)Str::ulid(),'provider_event_id'=>'label:'.$result->providerShipmentId,'status'=>ShipmentStatus::LabelCreated,'code'=>'label.created','message'=>'Shipping label created.','occurred_at'=>now(),'payload'=>['provider'=>$shipment->provider]]);
        }catch(\Throwable $e){$shipment->forceFill(['provider_synced_at'=>now(),'provider_sync_error'=>$e->getMessage()])->save();throw $e instanceof ShippingException?$e:new ShippingException('Courier shipment creation failed: '.$e->getMessage(),previous:$e);}
        return $shipment->fresh(['events','items.orderItem','vendorOrder.vendor']);
    }
}
