<?php
namespace App\Domain\Providers\Services;
use App\Domain\Kyc\Services\KycProviderManager;
use App\Domain\Notifications\Services\TransactionalEmailProviderManager;
use App\Domain\Payments\Services\PaymentGatewayManager;
use App\Domain\Payments\Services\PaymentVaultManager;
use App\Domain\Providers\Contracts\ProviderProbe;
use App\Domain\Providers\Data\ProviderProbeResult;
use App\Domain\Security\Services\SmsProviderManager;
use App\Domain\Shipping\Services\ShippingProviderManager;
use App\Models\ProviderRuntimeStatus;
use Illuminate\Support\Collection;
/** Defines the ProviderRuntimeService class and its project responsibilities. */
final class ProviderRuntimeService
{
    /** Initializes the ProviderRuntimeService instance and its dependencies. */
    public function __construct(private readonly PaymentGatewayManager $payments,private readonly PaymentVaultManager $vaults,private readonly ShippingProviderManager $shipping,private readonly SmsProviderManager $sms,private readonly TransactionalEmailProviderManager $email,private readonly KycProviderManager $kyc){}
    /** Handles probe all for the provider runtime service workflow. */
    public function probeAll():array
    {
        $targets=[];
        if((bool)config('vsn.payments.methods.card.enabled',false)){$code=(string)config('vsn.payments.methods.card.provider');$targets[]=['payment',$code,/** Inline callback for this operation. */ fn()=>$this->payments->gateway($code)];$targets[]=['payment_vault',$code,/** Inline callback for this operation. */ fn()=>$this->vaults->provider($code)];}
        foreach(collect(config('vsn.shipping_methods',[]))->where('enabled',true)->pluck('provider')->filter()->unique() as $code)$targets[]=['shipping',(string)$code,/** Inline callback for this operation. */ fn()=>$this->shipping->driver((string)$code)];
        $targets[]=['sms',(string)config('vsn.security.sms_provider','sandbox'),/** Inline callback for this operation. */ fn()=>$this->sms->provider()];
        $targets[]=['email',(string)config('vsn.notifications.email_provider','laravel_mail'),/** Inline callback for this operation. */ fn()=>$this->email->provider()];
        $targets[]=['kyc',(string)config('vsn.kyc.provider','manual'),/** Inline callback for this operation. */ fn()=>$this->kyc->provider()];
        $out=[];foreach($targets as [$type,$code,$factory])$out[]=$this->probeOne($type,$code,$factory);return $out;
    }
    /** Handles latest for the provider runtime service workflow. */
    public function latest():Collection{return ProviderRuntimeStatus::query()->orderBy('provider_type')->orderBy('provider_code')->get();}
    /** Handles fresh healthy for the provider runtime service workflow. */
    public function freshHealthy(string $type,string $code,int $maxAgeMinutes=15):bool
    {
        $row=ProviderRuntimeStatus::query()->where('provider_type',$type)->where('provider_code',$code)->first();return (bool)($row&&$row->status==='healthy'&&$row->production_ready&&$row->checked_at?->gte(now()->subMinutes($maxAgeMinutes)));
    }
    /** Handles probe one for the provider runtime service workflow. */
    private function probeOne(string $type,string $code,callable $factory):array
    {
        $started=microtime(true);
        try{$provider=$factory();$result=$provider instanceof ProviderProbe?$provider->probe():$this->configurationOnly($type,$code);}
        catch(\Throwable $e){$result=new ProviderProbeResult(false,false,'Provider probe failed: '.$e->getMessage(),['exception'=>class_basename($e)]);}
        $latency=(int)round((microtime(true)-$started)*1000);
        $row=ProviderRuntimeStatus::query()->updateOrCreate(['provider_type'=>$type,'provider_code'=>$code],['status'=>$result->healthy?'healthy':'unhealthy','production_ready'=>$result->productionReady,'latency_ms'=>$latency,'message'=>$result->message,'details'=>$result->details,'checked_at'=>now()]);
        return $this->row($row);
    }
    /** Handles configuration only for the provider runtime service workflow. */
    private function configurationOnly(string $type,string $code):ProviderProbeResult
    {
        $productionReady=!in_array($code,['sandbox','manual','laravel_mail',''],true);
        return new ProviderProbeResult($productionReady,$productionReady,$productionReady?'Provider is configured but has no active health-probe contract.':'Provider is development/manual only.');
    }
    /** Handles row for the provider runtime service workflow. */
    public function row(ProviderRuntimeStatus $r):array{return ['type'=>$r->provider_type,'code'=>$r->provider_code,'status'=>$r->status,'productionReady'=>$r->production_ready,'latencyMs'=>$r->latency_ms,'message'=>$r->message,'details'=>$r->details?:[],'checkedAt'=>$r->checked_at?->toIso8601String()];}
}
