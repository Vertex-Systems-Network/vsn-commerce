<?php
namespace App\Domain\Shipping\Actions;
use App\Domain\Shipping\Data\VerifiedShippingWebhook;
use App\Domain\Shipping\Exceptions\ShippingException;
use App\Domain\Shipping\Services\ShippingProviderManager;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
/** Defines the CancelShipment class and its project responsibilities. */
final class CancelShipment
{
    /** Initializes the CancelShipment instance and its dependencies. */
    public function __construct(private readonly ShippingProviderManager $providers, private readonly RecordShipmentEvent $record){}
    /** Executes the cancel shipment operation. */
    public function execute(Shipment $shipment): Shipment
    {
        $shipment->refresh();
        if($shipment->status===ShipmentStatus::Cancelled) return $shipment->load(['events','items.orderItem','vendorOrder.vendor']);
        if(!in_array($shipment->status,[ShipmentStatus::Pending,ShipmentStatus::LabelCreated,ShipmentStatus::ReadyForPickup],true)) throw new ShippingException('Shipment can only be cancelled before courier pickup.');
        if($shipment->provider_shipment_id){$this->providers->driver($shipment->provider)->cancelShipment($shipment);}
        $event=new VerifiedShippingWebhook('cancel-'.Str::uuid(),$shipment->provider_shipment_id,$shipment->tracking_number,ShipmentStatus::Cancelled,CarbonImmutable::now(),'shipment.cancelled','Shipment cancelled before pickup.',null,['source'=>'vsn']);
        return $this->record->execute($shipment,$event);
    }
}
