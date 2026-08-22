<?php
namespace App\Domain\Providers\Services;
use App\Domain\Kyc\Providers\HttpKycProvider;
use App\Domain\Kyc\Services\KycProviderManager;
use App\Domain\Payments\Services\PaymentGatewayManager;
use App\Domain\Shipping\Services\ShippingProviderManager;
use App\Models\KycVerification;
use App\Models\PaymentIntent;
use App\Models\ProviderReconciliationRun;
use App\Models\Shipment;
use Illuminate\Support\Str;
/** Defines the ProviderReconciliationService class and its project responsibilities. */
final class ProviderReconciliationService
{
    /** Initializes the ProviderReconciliationService instance and its dependencies. */
    public function __construct(private readonly PaymentGatewayManager $payments,private readonly ShippingProviderManager $shipping,private readonly KycProviderManager $kyc){}
    /** Executes the provider reconciliation service operation. */
    public function run(string $type,string $code,int $limit=200):ProviderReconciliationRun
    {
        $run=ProviderReconciliationRun::create(['public_id'=>(string)Str::ulid(),'provider_type'=>$type,'provider_code'=>$code,'status'=>'running','started_at'=>now()]);
        try{$result=match($type){'payment'=>$this->payments($code,$limit),'shipping'=>$this->shipping($code,$limit),'kyc'=>$this->kyc($code,$limit),default=>throw new \InvalidArgumentException('Unsupported provider reconciliation type.')};$run->update(['status'=>$result['errors']>0?'completed_with_errors':($result['mismatches']>0?'needs_review':'completed'),'checked_count'=>$result['checked'],'matched_count'=>$result['matched'],'mismatch_count'=>$result['mismatches'],'error_count'=>$result['errors'],'details'=>$result['details'],'completed_at'=>now()]);}
        catch(\Throwable $e){$run->update(['status'=>'failed','error_count'=>1,'details'=>['error'=>$e->getMessage()],'completed_at'=>now()]);}
        return $run->fresh();
    }
    /** Handles payments for the provider reconciliation service workflow. */
    private function payments(string $code,int $limit):array
    {
        $gateway=$this->payments->gateway($code);
        $checked=$matched=$mismatches=$errors=0;$details=[];
        PaymentIntent::query()->where('provider',$code)->whereNotNull('provider_payment_id')->latest('id')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(PaymentIntent $intent)use($gateway,&$checked,&$matched,&$mismatches,&$errors,&$details){$checked++;try{$remote=$gateway->lookupIntent($intent);$sameAmount=(int)($remote['amountMinor']??-1)===$intent->amount_minor;$sameCurrency=strtoupper((string)($remote['currency']??''))===$intent->currency;$localPaid=$intent->paid_at!==null;$remotePaid=in_array(($remote['status']??null),['succeeded','paid'],true);if($sameAmount&&$sameCurrency&&$localPaid===$remotePaid)$matched++;else{$mismatches++;$details[]=['id'=>$intent->public_id,'localStatus'=>$intent->status->value,'remoteStatus'=>$remote['status']??null,'amountMatch'=>$sameAmount,'currencyMatch'=>$sameCurrency];}}catch(\Throwable $e){$errors++;$details[]=['id'=>$intent->public_id,'error'=>class_basename($e)];}});
        return compact('checked','matched','mismatches','errors','details');
    }
    /** Handles shipping for the provider reconciliation service workflow. */
    private function shipping(string $code,int $limit):array
    {
        $provider=$this->shipping->driver($code);
        $checked=$matched=$mismatches=$errors=0;$details=[];
        Shipment::query()->where('provider',$code)->whereNotNull('provider_shipment_id')->latest('id')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(Shipment $shipment)use($provider,&$checked,&$matched,&$mismatches,&$errors,&$details){$checked++;try{$remote=$provider->lookupShipment($shipment);if(($remote['trackingNumber']??null)===$shipment->tracking_number&&($remote['status']??null)===$shipment->status->value)$matched++;else{$mismatches++;$details[]=['id'=>$shipment->public_id,'localStatus'=>$shipment->status->value,'remoteStatus'=>$remote['status']??null,'trackingMatch'=>($remote['trackingNumber']??null)===$shipment->tracking_number];}}catch(\Throwable $e){$errors++;$details[]=['id'=>$shipment->public_id,'error'=>class_basename($e)];}});
        return compact('checked','matched','mismatches','errors','details');
    }
    /** Handles kyc for the provider reconciliation service workflow. */
    private function kyc(string $code,int $limit):array
    {
        $provider=$this->kyc->provider();if($provider->name()!==$code||!method_exists($provider,'lookupVerification'))throw new \RuntimeException('This KYC provider has no reconciliation lookup adapter.');
        $checked=$matched=$mismatches=$errors=0;$details=[];
        KycVerification::query()->where('provider',$code)->whereNotNull('provider_reference')->latest('id')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(KycVerification $v)use($provider,&$checked,&$matched,&$mismatches,&$errors,&$details){$checked++;try{$remote=$provider->lookupVerification($v->provider_reference);$remoteStatus=(string)($remote['status']??'');if($remoteStatus===$v->status->value)$matched++;else{$mismatches++;$details[]=['id'=>$v->public_id,'localStatus'=>$v->status->value,'remoteStatus'=>$remoteStatus];}}catch(\Throwable $e){$errors++;$details[]=['id'=>$v->public_id,'error'=>class_basename($e)];}});
        return compact('checked','matched','mismatches','errors','details');
    }
}
