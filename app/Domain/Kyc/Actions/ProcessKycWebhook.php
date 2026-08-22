<?php
namespace App\Domain\Kyc\Actions;
use App\Domain\Kyc\Providers\HttpKycProvider;
use App\Domain\Kyc\Services\KycProviderManager;
use App\Enums\KycVerificationStatus;
use App\Models\KycVerification;
use App\Models\KycWebhookEvent;
use Illuminate\Database\QueryException;
/** Defines the ProcessKycWebhook class and its project responsibilities. */
final class ProcessKycWebhook
{
    /** Initializes the ProcessKycWebhook instance and its dependencies. */
    public function __construct(private readonly KycProviderManager $providers){}
    /** Executes the process kyc webhook operation. */
    public function execute(string $providerCode,string $rawPayload,array $headers):KycWebhookEvent
    {
        $provider=$this->providers->provider();if($provider->name()!==$providerCode||!$provider instanceof HttpKycProvider)throw new \RuntimeException('KYC webhook provider is not configured.');
        $data=$provider->verifyWebhook($rawPayload,$headers);$hash=hash('sha256',$rawPayload);
        $existing=KycWebhookEvent::query()->where('provider',$providerCode)->where('provider_event_id',$data['eventId'])->first();
        if($existing){if(!hash_equals($existing->payload_sha256,$hash))throw new \RuntimeException('KYC webhook replay payload mismatch.');return $existing;}
        try{$event=KycWebhookEvent::create(['provider'=>$providerCode,'provider_event_id'=>$data['eventId'],'payload_sha256'=>$hash,'status'=>'received','payload'=>$data,'received_at'=>now()]);}catch(QueryException){$event=KycWebhookEvent::query()->where('provider',$providerCode)->where('provider_event_id',$data['eventId'])->firstOrFail();if(!hash_equals($event->payload_sha256,$hash))throw new \RuntimeException('KYC webhook replay payload mismatch.');return $event;}
        try{$verification=KycVerification::query()->where('provider',$providerCode)->where('provider_reference',(string)$data['verificationId'])->firstOrFail();$status=$this->status((string)$data['status']);$update=['provider_payload'=>array_merge($verification->provider_payload??[],['last_webhook_status'=>$data['status'],'last_event_id'=>$data['eventId']]),'provider_last_sync_at'=>now(),'provider_last_error'=>null];if($status)$update['status']=$status;if($status===KycVerificationStatus::Approved->value){$update['reviewed_at']=now();$update['rejection_reason']=null;if(empty($data['expiresAt']))$update['expires_at']=now()->addDays($verification->type->value==='government_id'?(int)config('vsn.kyc.government_id_valid_days',365):(int)config('vsn.kyc.address_proof_valid_days',180));}if($status===KycVerificationStatus::Rejected->value){$update['reviewed_at']=now();$update['rejection_reason']=mb_substr((string)($data['reason']??'Provider verification rejected.'),0,2000);}if(!empty($data['expiresAt']))$update['expires_at']=$data['expiresAt'];$verification->update($update);$event->update(['status'=>'processed','processed_at'=>now()]);}
        catch(\Throwable $e){$event->update(['status'=>'failed','error'=>$e->getMessage(),'processed_at'=>now()]);throw $e;}
        return $event->fresh();
    }
    /** Handles status for the process kyc webhook workflow. */
    private function status(string $status):?string{return match(strtolower($status)){'approved','verified','passed'=>KycVerificationStatus::Approved->value,'rejected','failed','declined'=>KycVerificationStatus::Rejected->value,'expired'=>KycVerificationStatus::Expired->value,'pending','processing','review'=>KycVerificationStatus::Pending->value,default=>null};}
}
