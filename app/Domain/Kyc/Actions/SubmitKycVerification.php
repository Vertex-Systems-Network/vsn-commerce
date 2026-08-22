<?php
namespace App\Domain\Kyc\Actions;
use App\Domain\Kyc\Services\KycProviderManager;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the SubmitKycVerification class and its project responsibilities. */
final class SubmitKycVerification
{
    /** Initializes the SubmitKycVerification instance and its dependencies. */
    public function __construct(private readonly KycProviderManager $providers){}
    /** Executes the submit kyc verification operation. */
    public function execute(User $user,KycVerificationType $type,array $data):KycVerification
    {
        $provider=$this->providers->provider();
        $verification=DB::transaction(/** Inline callback for this operation. */ function()use($user,$type,$data,$provider){
            $active=KycVerification::query()->where('user_id',$user->id)->where('type',$type->value)->where(/** Inline callback for this operation. */ function($q){$q->where('status',KycVerificationStatus::Pending->value)->orWhere(/** Inline callback for this operation. */ function($a){$a->where('status',KycVerificationStatus::Approved->value)->where(/** Inline callback for this operation. */ fn($e)=>$e->whereNull('expires_at')->orWhere('expires_at','>',now()));});})->lockForUpdate()->latest('id')->first();
            if($active){if($active->status===KycVerificationStatus::Approved)abort(409,'This verification is already approved.');abort(409,'A verification is already pending review.');}
            $disk=(string)config('vsn.kyc.document_disk','local');$dir='kyc/'.$user->id.'/'.Str::uuid();$store=/** Inline callback for this operation. */ fn(?UploadedFile $file,string $name)=>$file?$file->storeAs($dir,$name.'.'.$file->extension(),$disk):null;$number=trim((string)($data['document_number']??''));
            return KycVerification::create(['public_id'=>(string)Str::uuid(),'user_id'=>$user->id,'type'=>$type,'status'=>KycVerificationStatus::Pending,'provider'=>$provider->name(),'document_number_cipher'=>$number?:null,'document_number_last4'=>$number?substr(preg_replace('/\s+/','',$number),-4):null,'country_code'=>isset($data['country_code'])?strtoupper($data['country_code']):null,'document_front_path'=>$store($data['document_front']??null,'front'),'document_back_path'=>$store($data['document_back']??null,'back'),'selfie_path'=>$store($data['selfie']??null,'selfie'),'address_proof_path'=>$store($data['address_proof']??null,'address-proof'),'submitted_at'=>now()]);
        },3);
        return $this->submitToProvider($verification);
    }
    /** Handles submit to provider for the submit kyc verification workflow. */
    public function submitToProvider(KycVerification $verification,bool $force=false):KycVerification
    {
        if($verification->provider_reference&&!$force)return $verification;
        if($verification->status!==KycVerificationStatus::Pending)return $verification;
        if(!$force&&$verification->next_provider_retry_at?->isFuture())return $verification;
        $max=max(1,(int)config('vsn.kyc.max_provider_attempts',5));if((int)$verification->provider_attempts>=$max)abort(409,'KYC provider retry limit reached. Contact support.');$attempt=(int)$verification->provider_attempts+1;$verification->forceFill(['provider_attempts'=>$attempt,'provider_last_attempt_at'=>now(),'provider_last_error'=>null])->save();
        try{
            $result=$this->providers->provider()->submit($verification);$verification->update(['provider_reference'=>$result['reference']??$verification->provider_reference,'provider_payload'=>array_merge($verification->provider_payload??[],$result['payload']??[]),'provider_last_error'=>null,'next_provider_retry_at'=>null]);
        }catch(\Throwable $e){$backoff=min(1440,2**min(10,$attempt));$verification->update(['provider_last_error'=>mb_substr($e->getMessage(),0,2000),'next_provider_retry_at'=>now()->addMinutes($backoff),'provider_payload'=>array_merge($verification->provider_payload??[],['submission_error'=>mb_substr($e->getMessage(),0,1500),'submission_failed_at'=>now()->toIso8601String()])]);report($e);}
        return $verification->fresh();
    }
}
