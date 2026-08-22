<?php
namespace App\Domain\Notifications\Providers;
use App\Domain\Notifications\Contracts\TransactionalEmailProvider;
use App\Domain\Providers\Contracts\ProviderProbe;
use App\Domain\Providers\Data\ProviderProbeResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;
/** Defines the ResendEmailProvider class and its project responsibilities. */
final class ResendEmailProvider implements TransactionalEmailProvider,ProviderProbe
{
    /** Initializes the ResendEmailProvider instance and its dependencies. */
    public function __construct(private readonly string $apiKey,private readonly string $from,private readonly string $apiBase='https://api.resend.com'){}
    /** Handles name for the resend email provider workflow. */
    public function name():string{return 'resend';}
    /** Handles provider type for the resend email provider workflow. */
    public function providerType():string{return 'email';}
    /** Handles provider code for the resend email provider workflow. */
    public function providerCode():string{return 'resend';}
    /** Handles send for the resend email provider workflow. */
    public function send(string $to,string $subject,string $text,?string $html=null,?string $idempotencyKey=null):void
    {
        $this->assertConfigured();$request=Http::withToken($this->apiKey)->acceptJson()->timeout(15)->retry(2,250,throw:false);
        if($idempotencyKey)$request=$request->withHeader('Idempotency-Key',$idempotencyKey);
        $r=$request->post(rtrim($this->apiBase,'/').'/emails',['from'=>$this->from,'to'=>[$to],'subject'=>$subject,'text'=>$text,'html'=>$html]);
        if(!$r->successful())throw new RuntimeException('Resend email delivery failed (HTTP '.$r->status().').');
        if((string)($r->json('id')??'')==='')throw new RuntimeException('Resend returned no email ID.');
    }
    /** Handles probe for the resend email provider workflow. */
    public function probe():ProviderProbeResult
    {
        if(!$this->configured())return new ProviderProbeResult(false,false,'Resend API key/from address are incomplete.');
        $r=Http::withToken($this->apiKey)->acceptJson()->timeout(8)->get(rtrim($this->apiBase,'/').'/domains');$healthy=$r->successful();$fromEmail=preg_match('/<([^>]+)>/',$this->from,$m)?$m[1]:$this->from;$fromDomain=strtolower((string)substr(strrchr($fromEmail,'@')?:'',1));$domains=(array)($r->json('data')??[]);$verified=false;foreach($domains as $domain){if(strtolower((string)($domain['name']??''))===$fromDomain&&strtolower((string)($domain['status']??''))==='verified'){$verified=true;break;}}$ready=$healthy&&$verified;
        return new ProviderProbeResult($healthy,$ready,$ready?'Resend API and sender domain are verified.':($healthy?'Resend API is reachable, but the configured sender domain is not verified.':'Resend API probe failed.'),['httpStatus'=>$r->status(),'senderDomain'=>$fromDomain,'senderDomainVerified'=>$verified]);
    }
    /** Handles configured for the resend email provider workflow. */
    private function configured():bool{return str_starts_with($this->apiKey,'re_')&&str_contains($this->from,'@');}
    /** Handles assert configured for the resend email provider workflow. */
    private function assertConfigured():void{if(!$this->configured())throw new RuntimeException('Resend transactional email provider is not fully configured.');}
}
