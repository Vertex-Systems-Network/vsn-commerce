<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Kyc\Actions\SubmitKycVerification;
use App\Domain\Kyc\Services\KycLifecycleService;
use App\Domain\Security\Services\SecureUploadInspector;
use App\Enums\KycVerificationType;
use App\Http\Controllers\Controller;
use App\Models\KycVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
/** Defines the KycController class and its project responsibilities. */
class KycController extends Controller {
 /** Handles the index request for this resource. */
 public function index(Request $r):JsonResponse{return response()->json(['data'=>['emailVerified'=>$r->user()->email_verified_at!==null,'phoneVerified'=>$r->user()->profile?->phone_verified_at!==null,'items'=>$r->user()->kycVerifications()->latest()->get()->map(/** Inline callback for this operation. */ fn($v)=>$this->row($v))->all()]]);}
 /** Handles the store request for this resource. */
 public function store(Request $r,SubmitKycVerification $action,SecureUploadInspector $uploads):JsonResponse
 {
  $d=$r->validate(['type'=>'required|in:government_id,address_proof','document_number'=>'nullable|string|max:100','country_code'=>'nullable|string|size:2','document_front'=>'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240','document_back'=>'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240','selfie'=>'nullable|image|mimes:jpg,jpeg,png,webp|max:10240','address_proof'=>'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240']);
  $type=KycVerificationType::from($d['type']);if($type===KycVerificationType::GovernmentId){if(empty($d['document_number'])||!$r->file('document_front'))abort(422,'Government ID number and front document are required.');}else{if(!$r->file('address_proof'))abort(422,'Address proof document is required.');}
  foreach(['document_front','document_back','selfie','address_proof'] as $field){if($file=$r->file($field))$uploads->inspect($file,['image/jpeg','image/png','image/webp','application/pdf'],10_485_760,true);$d[$field]=$file??null;}
  $v=$action->execute($r->user(),$type,$d);return response()->json(['data'=>$this->row($v)],201);
 }
 /** Handles retry for the kyc controller workflow. */
 public function retry(Request $r,KycVerification $verification,KycLifecycleService $lifecycle):JsonResponse{abort_unless($verification->user_id===$r->user()->id,404);return response()->json(['data'=>$this->row($lifecycle->retry($verification))]);}
 /** Handles document for the kyc controller workflow. */
 public function document(Request $r,KycVerification $verification,string $kind){abort_unless($verification->user_id===$r->user()->id || $this->canReview($r),403);$field=['front'=>'document_front_path','back'=>'document_back_path','selfie'=>'selfie_path','address-proof'=>'address_proof_path'][$kind]??null;abort_unless($field,404);$path=$verification->{$field};abort_unless($path,404);return Storage::disk(config('vsn.kyc.document_disk','local'))->download($path);}
 /** Handles row for the kyc controller workflow. */
 private function row($v):array{return ['id'=>$v->public_id,'type'=>$v->type->value,'status'=>$v->status->value,'provider'=>$v->provider,'documentLast4'=>$v->document_number_last4,'countryCode'=>$v->country_code,'rejectionReason'=>$v->rejection_reason,'submittedAt'=>$v->submitted_at?->toIso8601String(),'reviewedAt'=>$v->reviewed_at?->toIso8601String(),'expiresAt'=>$v->expires_at?->toIso8601String(),'providerAttempts'=>(int)$v->provider_attempts,'providerLastError'=>$v->provider_last_error,'nextProviderRetryAt'=>$v->next_provider_retry_at?->toIso8601String(),'providerLastSyncAt'=>$v->provider_last_sync_at?->toIso8601String()];}
 /** Handles can review for the kyc controller workflow. */
 private function canReview(Request $r):bool{$v=$r->user()?->role?->value??(string)$r->user()?->role;return in_array($v,['moderator','admin','super_admin'],true);}
}
