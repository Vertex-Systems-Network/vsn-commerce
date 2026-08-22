<?php
namespace App\Domain\Shipping\Providers;
use App\Domain\Providers\Contracts\ProviderProbe;
use App\Domain\Providers\Data\ProviderProbeResult;
use App\Domain\Shipping\Contracts\ShippingProvider;
use App\Domain\Shipping\Data\ShipmentLabelResult;
use App\Domain\Shipping\Data\VerifiedShippingWebhook;
use App\Domain\Shipping\Exceptions\ShippingException;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
/** Defines the HttpShippingProvider class and its project responsibilities. */
final class HttpShippingProvider implements ShippingProvider,ProviderProbe
{
    /** Initializes the HttpShippingProvider instance and its dependencies. */
    public function __construct(private readonly string $code,private readonly string $baseUrl,private readonly string $apiToken,private readonly string $webhookSecret,private readonly string $createPath='/shipments',private readonly string $healthPath='/health'){}
    /** Handles code for the http shipping provider workflow. */
    public function code():string{return $this->code;}
    /** Handles provider type for the http shipping provider workflow. */
    public function providerType():string{return 'shipping';}
    /** Handles provider code for the http shipping provider workflow. */
    public function providerCode():string{return $this->code;}
    /** Handles create shipment for the http shipping provider workflow. */
    public function createShipment(Shipment $shipment,array $recipientSnapshot):ShipmentLabelResult
    {
        $this->assertConfigured();$shipment->loadMissing(['items.orderItem','vendorOrder.vendor']);
        $payload=['externalId'=>$shipment->public_id,'serviceCode'=>$shipment->service_code,'recipient'=>$this->recipient($recipientSnapshot),'items'=>$shipment->items->map(/** Inline callback for this operation. */ fn($i)=>['sku'=>$i->orderItem?->sku,'name'=>$i->orderItem?->product_name,'quantity'=>$i->quantity])->values()->all(),'metadata'=>['orderId'=>$shipment->order?->public_id,'vendorOrderId'=>$shipment->vendorOrder?->public_id,'vendor'=>$shipment->vendorOrder?->vendor?->name]];
        $r=$this->request()->withHeader('Idempotency-Key','vsn-shipment-'.$shipment->public_id)->post($this->url($this->createPath),$payload);if(!$r->successful())throw new ShippingException("Courier [{$this->code}] shipment creation failed (HTTP {$r->status()}).");$d=$r->json();
        $id=(string)($d['shipmentId']??$d['id']??'');$tracking=(string)($d['trackingNumber']??$d['tracking_number']??'');if($id===''||$tracking==='')throw new ShippingException("Courier [{$this->code}] returned an incomplete shipment response.");
        $eta=null;if(!empty($d['estimatedDeliveryAt']))$eta=CarbonImmutable::parse($d['estimatedDeliveryAt']);
        return new ShipmentLabelResult($id,$tracking,$d['labelUrl']??$d['label_url']??null,$eta,['provider_response_code'=>$r->status()]);
    }
    /** Handles verify webhook for the http shipping provider workflow. */
    public function verifyWebhook(string $rawPayload,array $headers):VerifiedShippingWebhook
    {
        $signature=$this->firstHeader($headers,'x-vsn-signature');$expected='sha256='.hash_hmac('sha256',$rawPayload,$this->webhookSecret);
        if(!$signature||!hash_equals($expected,$signature))throw new ShippingException('Courier webhook signature is invalid.');
        $d=json_decode($rawPayload,true);if(!is_array($d))throw new ShippingException('Courier webhook payload is invalid JSON.');
        $status=$this->mapStatus((string)($d['status']??''));$eventId=(string)($d['eventId']??$d['event_id']??'');if($eventId==='')throw new ShippingException('Courier webhook event ID is missing.');
        return new VerifiedShippingWebhook($eventId,$d['shipmentId']??$d['shipment_id']??null,$d['trackingNumber']??$d['tracking_number']??null,$status,CarbonImmutable::parse($d['occurredAt']??$d['occurred_at']??'now'),$d['code']??null,$d['message']??null,$d['location']??null,$d);
    }
    /** Handles lookup shipment for the http shipping provider workflow. */
    public function lookupShipment(Shipment $shipment):array
    {
        $this->assertConfigured();$path=(string)config('vsn.shipping.providers.courier_http.lookup_path','/shipments/{id}');$path=str_replace('{id}',rawurlencode((string)$shipment->provider_shipment_id),$path);$r=$this->request()->get($this->url($path));if(!$r->successful())throw new ShippingException("Courier lookup failed (HTTP {$r->status()}).");$d=$r->json();return ['trackingNumber'=>$d['trackingNumber']??$d['tracking_number']??null,'status'=>$this->mapStatus((string)($d['status']??''))->value,'raw'=>$d];
    }
    /** Handles cancel shipment for the http shipping provider workflow. */
    public function cancelShipment(Shipment $shipment):array
    {
        $this->assertConfigured();
        if(!$shipment->provider_shipment_id) return ['cancelled'=>true];
        $path=(string)config('vsn.shipping.providers.courier_http.cancel_path','/shipments/{id}/cancel');
        $path=str_replace('{id}',rawurlencode((string)$shipment->provider_shipment_id),$path);
        $r=$this->request()->withHeader('Idempotency-Key','vsn-shipment-cancel-'.$shipment->public_id)->post($this->url($path),['externalId'=>$shipment->public_id]);
        if(!$r->successful())throw new ShippingException("Courier cancellation failed (HTTP {$r->status()}).");
        return is_array($r->json())?$r->json():['cancelled'=>true];
    }
    /** Handles probe for the http shipping provider workflow. */
    public function probe():ProviderProbeResult
    {
        if(!$this->configured())return new ProviderProbeResult(false,false,"Courier [{$this->code}] credentials are incomplete.");
        $r=$this->request()->timeout(8)->get($this->url($this->healthPath));return new ProviderProbeResult($r->successful(),$r->successful(),$r->successful()?"Courier [{$this->code}] health probe succeeded.":"Courier [{$this->code}] health probe failed.",['httpStatus'=>$r->status()]);
    }
    /** Handles recipient for the http shipping provider workflow. */
    private function recipient(array $a):array{return ['name'=>$a['name']??null,'phone'=>$a['phone']??null,'line1'=>$a['line1']??$a['address_line1']??null,'line2'=>$a['line2']??$a['address_line2']??null,'city'=>$a['city']??null,'region'=>$a['region']??$a['state']??null,'postalCode'=>$a['postal_code']??$a['postalCode']??null,'countryCode'=>$a['country_code']??$a['countryCode']??null];}
    /** Handles request for the http shipping provider workflow. */
    private function request(){return Http::withToken($this->apiToken)->acceptJson()->timeout(20)->retry(2,300,throw:false);}
    /** Handles url for the http shipping provider workflow. */
    private function url(string $path):string{return rtrim($this->baseUrl,'/').'/'.ltrim($path,'/');}
    /** Handles configured for the http shipping provider workflow. */
    private function configured():bool{return str_starts_with($this->baseUrl,'https://')&&$this->apiToken!==''&&strlen($this->webhookSecret)>=24;}
    /** Handles assert configured for the http shipping provider workflow. */
    private function assertConfigured():void{if(!$this->configured())throw new ShippingException("Courier [{$this->code}] provider is not fully configured.");}
    /** Handles first header for the http shipping provider workflow. */
    private function firstHeader(array $headers,string $name):?string{foreach($headers as $k=>$v)if(strtolower((string)$k)===$name)return is_array($v)?($v[0]??null):(string)$v;return null;}
    /** Handles map status for the http shipping provider workflow. */
    private function mapStatus(string $status):ShipmentStatus{return match(strtolower($status)){'pending'=>ShipmentStatus::Pending,'label_created','created'=>ShipmentStatus::LabelCreated,'ready','ready_for_pickup'=>ShipmentStatus::ReadyForPickup,'picked_up','pickup'=>ShipmentStatus::PickedUp,'in_transit','transit'=>ShipmentStatus::InTransit,'out_for_delivery'=>ShipmentStatus::OutForDelivery,'delivered'=>ShipmentStatus::Delivered,'delivery_failed','failed'=>ShipmentStatus::DeliveryFailed,'return_to_origin','rto'=>ShipmentStatus::ReturnToOrigin,'returned_to_sender','returned'=>ShipmentStatus::ReturnedToSender,'cancelled','canceled'=>ShipmentStatus::Cancelled,default=>throw new ShippingException("Unsupported courier shipment status [{$status}].")};}
}
