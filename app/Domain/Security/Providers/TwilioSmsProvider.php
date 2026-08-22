<?php
namespace App\Domain\Security\Providers;
use App\Domain\Providers\Contracts\ProviderProbe;
use App\Domain\Providers\Data\ProviderProbeResult;
use App\Domain\Security\Contracts\SmsProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
/** Defines the TwilioSmsProvider class and its project responsibilities. */
final class TwilioSmsProvider implements SmsProvider,ProviderProbe
{
    /** Initializes the TwilioSmsProvider instance and its dependencies. */
    public function __construct(private readonly string $accountSid,private readonly string $authToken,private readonly ?string $from,private readonly ?string $messagingServiceSid,private readonly string $apiBase='https://api.twilio.com'){}
    /** Handles name for the twilio sms provider workflow. */
    public function name():string{return 'twilio';}
    /** Handles provider type for the twilio sms provider workflow. */
    public function providerType():string{return 'sms';}
    /** Handles provider code for the twilio sms provider workflow. */
    public function providerCode():string{return 'twilio';}
    /** Handles send for the twilio sms provider workflow. */
    public function send(string $phone,string $message):void
    {
        $this->assertConfigured();
        $form=['To'=>$phone,'Body'=>$message];
        if($this->messagingServiceSid)$form['MessagingServiceSid']=$this->messagingServiceSid;else $form['From']=$this->from;
        $r=Http::withBasicAuth($this->accountSid,$this->authToken)->acceptJson()->asForm()->timeout(15)->retry(2,250,throw:false)
            ->post(rtrim($this->apiBase,'/').'/2010-04-01/Accounts/'.rawurlencode($this->accountSid).'/Messages.json',$form);
        if(!$r->successful())throw new RuntimeException('Twilio SMS send failed (HTTP '.$r->status().').');
        if(!str_starts_with((string)($r->json('sid')??''),'SM'))throw new RuntimeException('Twilio returned no message SID.');
    }
    /** Handles probe for the twilio sms provider workflow. */
    public function probe():ProviderProbeResult
    {
        if(!$this->configured())return new ProviderProbeResult(false,false,'Twilio credentials/sender are incomplete.');
        $r=Http::withBasicAuth($this->accountSid,$this->authToken)->acceptJson()->timeout(8)->get(rtrim($this->apiBase,'/').'/2010-04-01/Accounts/'.rawurlencode($this->accountSid).'.json');
        $healthy=$r->successful();$active=$healthy&&strtolower((string)$r->json('status'))==='active';return new ProviderProbeResult($healthy,$active,$active?'Twilio active account probe succeeded.':($healthy?'Twilio API is reachable, but the account is not active.':'Twilio account probe failed.'),['httpStatus'=>$r->status(),'accountStatus'=>$r->json('status')]);
    }
    /** Handles configured for the twilio sms provider workflow. */
    private function configured():bool{return str_starts_with($this->accountSid,'AC')&&$this->authToken!==''&&(($this->messagingServiceSid&&str_starts_with($this->messagingServiceSid,'MG'))||($this->from&&$this->from!==''));}
    /** Handles assert configured for the twilio sms provider workflow. */
    private function assertConfigured():void{if(!$this->configured())throw new RuntimeException('Twilio SMS provider is not fully configured.');}
}
