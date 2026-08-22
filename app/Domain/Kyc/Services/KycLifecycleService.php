<?php
namespace App\Domain\Kyc\Services;
use App\Domain\Kyc\Actions\SubmitKycVerification;
use App\Domain\Kyc\Providers\HttpKycProvider;
use App\Enums\KycVerificationStatus;
use App\Models\KycVerification;
/** Defines the KycLifecycleService class and its project responsibilities. */
final class KycLifecycleService
{
    /** Initializes the KycLifecycleService instance and its dependencies. */
    public function __construct(private readonly KycProviderManager $providers,private readonly SubmitKycVerification $submit){}
    /** Handles sync for the kyc lifecycle service workflow. */
    public function sync(KycVerification $verification):KycVerification
    {
        $provider=$this->providers->provider();
        if(!$verification->provider_reference||!$provider instanceof HttpKycProvider)abort(409,'This verification has no synchronizable provider reference.');
        try{$remote=$provider->lookupVerification($verification->provider_reference);$status=$this->status((string)($remote['status']??''));$update=['provider_last_sync_at'=>now(),'provider_last_error'=>null,'provider_payload'=>array_merge($verification->provider_payload??[],['last_lookup'=>$remote])];if($status)$update['status']=$status;if($status===KycVerificationStatus::Approved->value){$update['rejection_reason']=null;$update['reviewed_at']=$verification->reviewed_at?:now();if(!$verification->expires_at)$update['expires_at']=now()->addDays($verification->type->value==='government_id'?(int)config('vsn.kyc.government_id_valid_days',365):(int)config('vsn.kyc.address_proof_valid_days',180));}if($status===KycVerificationStatus::Rejected->value)$update['reviewed_at']=$verification->reviewed_at?:now();$verification->update($update);}catch(\Throwable $e){$verification->update(['provider_last_sync_at'=>now(),'provider_last_error'=>mb_substr($e->getMessage(),0,2000)]);throw $e;}return $verification->fresh();
    }
    /** Handles retry for the kyc lifecycle service workflow. */
    public function retry(KycVerification $verification):KycVerification
    {
        if($verification->status!==KycVerificationStatus::Pending)abort(409,'Only pending KYC submissions can be retried.');if($verification->provider==='manual')abort(409,'Manual verification does not require provider retry.');
        if($verification->provider_reference)return $this->sync($verification);
        return $this->submit->submitToProvider($verification,true);
    }
    /** Handles reconcile for the kyc lifecycle service workflow. */
    public function reconcile(int $limit=100):array
    {
        $expired=KycVerification::query()->where('status',KycVerificationStatus::Approved->value)->whereNotNull('expires_at')->where('expires_at','<=',now())->update(['status'=>KycVerificationStatus::Expired->value,'updated_at'=>now()]);$retried=0;$failed=0;
        KycVerification::query()->where('status',KycVerificationStatus::Pending->value)->whereNull('provider_reference')->where('provider_attempts','<',max(1,(int)config('vsn.kyc.max_provider_attempts',5)))->whereNotNull('next_provider_retry_at')->where('next_provider_retry_at','<=',now())->orderBy('id')->limit($limit)->get()->each(/** Inline callback for this operation. */ function($v)use(&$retried,&$failed){try{$result=$this->submit->submitToProvider($v,true);if($result->provider_last_error)$failed++;else$retried++;}catch(\Throwable){$failed++;}});
        return compact('expired','retried','failed');
    }
    /** Handles status for the kyc lifecycle service workflow. */
    private function status(string $status):?string{return match(strtolower($status)){'approved','verified','passed'=>KycVerificationStatus::Approved->value,'rejected','failed','declined'=>KycVerificationStatus::Rejected->value,'expired'=>KycVerificationStatus::Expired->value,'pending','processing','review'=>KycVerificationStatus::Pending->value,default=>null};}
}
