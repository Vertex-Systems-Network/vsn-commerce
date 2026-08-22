<?php
namespace App\Domain\Shipping\Actions;
use App\Domain\Shipping\Exceptions\ShippingException;
use App\Domain\Shipping\Services\ShippingProviderManager;
use App\Models\Shipment;
use App\Models\ShippingWebhookEvent;
use Illuminate\Database\QueryException;
/** Defines the ProcessShippingWebhook class and its project responsibilities. */
class ProcessShippingWebhook
{
    /** Initializes the ProcessShippingWebhook instance and its dependencies. */
    public function __construct(private readonly ShippingProviderManager $providers,private readonly RecordShipmentEvent $record){}
    /** Executes the process shipping webhook operation. */
    public function execute(string $provider,string $rawPayload,array $headers):Shipment
    {
        $verified=$this->providers->driver($provider)->verifyWebhook($rawPayload,$headers);
        $hash=hash('sha256',$rawPayload);
        $webhook=ShippingWebhookEvent::query()->where('provider',$provider)->where('provider_event_id',$verified->eventId)->first();
        if($webhook && !hash_equals($webhook->payload_hash,$hash)){
            $webhook->update(['status'=>'rejected','error'=>'Duplicate event id with different payload hash.','processed_at'=>now()]);
            throw new ShippingException('Shipping webhook replay payload mismatch.');
        }
        if($webhook && $webhook->status==='processed'){
            $webhook->increment('duplicate_count');$webhook->forceFill(['last_duplicate_at'=>now()])->save();
            $shipment=$this->shipment($provider,$verified->providerShipmentId,$verified->trackingNumber);return $shipment->load(['events','items.orderItem','vendorOrder.vendor']);
        }
        if(!$webhook){
            try{$webhook=ShippingWebhookEvent::create(['provider'=>$provider,'provider_event_id'=>$verified->eventId,'payload_hash'=>$hash,'signature_valid'=>true,'status'=>'received','payload'=>$verified->payload,'received_at'=>now()]);}
            catch(QueryException){$webhook=ShippingWebhookEvent::query()->where('provider',$provider)->where('provider_event_id',$verified->eventId)->firstOrFail();}
        }
        if(!hash_equals($webhook->payload_hash,$hash)){ $webhook->update(['status'=>'rejected','error'=>'Duplicate event id with different payload hash.','processed_at'=>now()]); throw new ShippingException('Shipping webhook replay payload mismatch.'); }
        try{
            $shipment=$this->shipment($provider,$verified->providerShipmentId,$verified->trackingNumber);
            $shipment->forceFill(['provider_status'=>$verified->status->value,'provider_synced_at'=>now(),'provider_sync_error'=>null])->save();
            $shipment=$this->record->execute($shipment,$verified);
            $webhook->update(['status'=>'processed','processed_at'=>now(),'error'=>null]);return $shipment;
        }catch(\Throwable $e){$webhook->update(['status'=>'failed','processed_at'=>now(),'error'=>$e->getMessage()]);throw $e;}
    }
    /** Handles shipment for the process shipping webhook workflow. */
    private function shipment(string $provider,?string $providerShipmentId,?string $tracking):Shipment
    {
        $q=Shipment::query()->where('provider',$provider);
        if($providerShipmentId)$q->where('provider_shipment_id',$providerShipmentId);elseif($tracking)$q->where('tracking_number',$tracking);else throw new ShippingException('Webhook has no shipment reference.');
        return $q->firstOrFail();
    }
}
