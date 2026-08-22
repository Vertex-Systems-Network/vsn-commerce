<?php
namespace App\Domain\Kyc\Providers;
use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Providers\Contracts\ProviderProbe;
use App\Domain\Providers\Data\ProviderProbeResult;
use App\Models\KycVerification;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
/** Defines the HttpKycProvider class and its project responsibilities. */
final class HttpKycProvider implements KycProvider,ProviderProbe
{
    /** Initializes the HttpKycProvider instance and its dependencies. */
    public function __construct(private readonly string $baseUrl,private readonly string $apiToken,private readonly string $webhookSecret,private readonly string $submitPath='/verifications',private readonly string $healthPath='/health'){}
    /** Handles name for the http kyc provider workflow. */
    public function name():string{return 'kyc_http';}
    /** Handles provider type for the http kyc provider workflow. */
    public function providerType():string{return 'kyc';}
    /** Handles provider code for the http kyc provider workflow. */
    public function providerCode():string{return 'kyc_http';}
    /** Handles submit for the http kyc provider workflow. */
    public function submit(KycVerification $verification):array
    {
        $this->assertConfigured();$disk=(string)config('vsn.kyc.document_disk','local');$request=$this->request();
        foreach(['document_front_path'=>'document_front','document_back_path'=>'document_back','selfie_path'=>'selfie','address_proof_path'=>'address_proof'] as $field=>$name){$path=$verification->{$field};if($path&&Storage::disk($disk)->exists($path))$request=$request->attach($name,Storage::disk($disk)->get($path),basename($path));}
        $r=$request->post($this->url($this->submitPath),['externalId'=>$verification->public_id,'type'=>$verification->type->value,'countryCode'=>$verification->country_code,'documentNumberLast4'=>$verification->document_number_last4]);
        if(!$r->successful())throw new RuntimeException('KYC provider submission failed (HTTP '.$r->status().').');$d=$r->json();$ref=(string)($d['verificationId']??$d['id']??'');if($ref==='')throw new RuntimeException('KYC provider returned no verification reference.');
        return ['status'=>'pending','reference'=>$ref,'payload'=>['provider_status'=>$d['status']??'pending']];
    }
    /** Handles verify webhook for the http kyc provider workflow. */
    public function verifyWebhook(string $rawPayload,array $headers):array
    {
        $signature=$this->firstHeader($headers,'x-vsn-signature');$expected='sha256='.hash_hmac('sha256',$rawPayload,$this->webhookSecret);if(!$signature||!hash_equals($expected,$signature))throw new RuntimeException('KYC webhook signature is invalid.');
        $d=json_decode($rawPayload,true);if(!is_array($d))throw new RuntimeException('KYC webhook JSON is invalid.');
        foreach(['eventId','verificationId','status'] as $key)if(empty($d[$key]))throw new RuntimeException("KYC webhook field [{$key}] is missing.");return $d;
    }
    /** Handles lookup verification for the http kyc provider workflow. */
    public function lookupVerification(string $reference):array
    {
        $this->assertConfigured();$path=(string)config('vsn.kyc.providers.kyc_http.lookup_path','/verifications/{id}');$path=str_replace('{id}',rawurlencode($reference),$path);$r=$this->request()->get($this->url($path));if(!$r->successful())throw new RuntimeException('KYC provider lookup failed (HTTP '.$r->status().').');return $r->json();
    }
    /** Handles probe for the http kyc provider workflow. */
    public function probe():ProviderProbeResult
    {
        if(!$this->configured())return new ProviderProbeResult(false,false,'KYC HTTP provider credentials are incomplete.');$r=$this->request()->timeout(8)->get($this->url($this->healthPath));return new ProviderProbeResult($r->successful(),$r->successful(),$r->successful()?'KYC provider health probe succeeded.':'KYC provider health probe failed.',['httpStatus'=>$r->status()]);
    }
    /** Handles request for the http kyc provider workflow. */
    private function request():PendingRequest{return Http::withToken($this->apiToken)->acceptJson()->timeout(30)->retry(2,300,throw:false);}
    /** Handles url for the http kyc provider workflow. */
    private function url(string $p):string{return rtrim($this->baseUrl,'/').'/'.ltrim($p,'/');}
    /** Handles configured for the http kyc provider workflow. */
    private function configured():bool{return str_starts_with($this->baseUrl,'https://')&&$this->apiToken!==''&&strlen($this->webhookSecret)>=24;}
    /** Handles assert configured for the http kyc provider workflow. */
    private function assertConfigured():void{if(!$this->configured())throw new RuntimeException('KYC HTTP provider is not fully configured.');}
    /** Handles first header for the http kyc provider workflow. */
    private function firstHeader(array $headers,string $name):?string{foreach($headers as $k=>$v)if(strtolower((string)$k)===$name)return is_array($v)?($v[0]??null):(string)$v;return null;}
}
