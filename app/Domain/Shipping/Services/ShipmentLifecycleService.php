<?php
namespace App\Domain\Shipping\Services;
use App\Domain\Shipping\Actions\RecordShipmentEvent;
use App\Domain\Shipping\Data\VerifiedShippingWebhook;
use App\Domain\Shipping\Exceptions\ShippingException;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;
/** Defines the ShipmentLifecycleService class and its project responsibilities. */
final class ShipmentLifecycleService
{
    /** Initializes the ShipmentLifecycleService instance and its dependencies. */
    public function __construct(private readonly ShippingProviderManager $providers,private readonly RecordShipmentEvent $record){}
    /** Handles sync for the shipment lifecycle service workflow. */
    public function sync(Shipment $shipment): Shipment
    {
        $shipment->refresh();
        if(!$shipment->provider_shipment_id)return $shipment;
        try{
            $remote=$this->providers->driver($shipment->provider)->lookupShipment($shipment);
            $status=$remote['status']??null;
            $tracking=$remote['trackingNumber']??null;
            $shipment->forceFill(['provider_status'=>$status,'provider_synced_at'=>now(),'provider_sync_error'=>null])->save();
            if($tracking && !$shipment->tracking_number)$shipment->forceFill(['tracking_number'=>$tracking])->save();
            if($status && $status!==$shipment->status->value){
                $enum=\App\Enums\ShipmentStatus::tryFrom((string)$status);
                if($enum){
                    $event=new VerifiedShippingWebhook('sync-'.Str::uuid(),$shipment->provider_shipment_id,$tracking,$enum,CarbonImmutable::now(),'provider.sync','Provider status reconciliation.',null,$remote['raw']??$remote);
                    $shipment=$this->record->execute($shipment,$event);
                }
            }
            return $shipment->fresh(['events','items.orderItem','vendorOrder.vendor']);
        }catch(Throwable $e){$shipment->forceFill(['provider_synced_at'=>now(),'provider_sync_error'=>$e->getMessage()])->save();throw $e instanceof ShippingException?$e:new ShippingException('Courier status refresh failed: '.$e->getMessage(),previous:$e);}
    }
}
