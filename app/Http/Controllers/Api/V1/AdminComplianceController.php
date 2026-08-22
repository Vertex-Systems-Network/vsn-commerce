<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Kyc\Actions\ReviewKycVerification;
use App\Enums\KycVerificationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Domain\Kyc\Services\KycLifecycleService;
use App\Domain\Kyc\Actions\SubmitKycVerification;
use App\Domain\Notifications\Actions\PublishMarketplaceNotification;
use App\Models\AdminAuditLog;
use App\Models\KycVerification;
use App\Models\SecurityEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the AdminComplianceController class and its project responsibilities. */
class AdminComplianceController extends Controller {
 /** Handles kyc for the admin compliance controller workflow. */
 public function kyc(Request $r):JsonResponse{$this->reviewer($r);$status=$r->string('status')->toString()?:KycVerificationStatus::Pending->value;$rows=KycVerification::query()->where('status',$status)->with('user:id,name,email')->latest('submitted_at')->limit(200)->get();return response()->json(['data'=>$rows->map(/** Inline callback for this operation. */ fn($v)=>['id'=>$v->public_id,'user'=>['id'=>$v->user_id,'name'=>$v->user?->name,'email'=>$v->user?->email],'type'=>$v->type->value,'status'=>$v->status->value,'documentLast4'=>$v->document_number_last4,'countryCode'=>$v->country_code,'submittedAt'=>$v->submitted_at?->toIso8601String(),'rejectionReason'=>$v->rejection_reason,'expiresAt'=>$v->expires_at?->toIso8601String(),'provider'=>$v->provider,'providerReference'=>$v->provider_reference,'providerAttempts'=>(int)$v->provider_attempts,'providerLastError'=>$v->provider_last_error,'providerLastSyncAt'=>$v->provider_last_sync_at?->toIso8601String(),'nextProviderRetryAt'=>$v->next_provider_retry_at?->toIso8601String(),'documents'=>['front'=>(bool)$v->document_front_path,'back'=>(bool)$v->document_back_path,'selfie'=>(bool)$v->selfie_path,'addressProof'=>(bool)$v->address_proof_path]])->all()]);}
 /** Handles review for the admin compliance controller workflow. */
 public function review(Request $r,KycVerification $verification,ReviewKycVerification $action,PublishMarketplaceNotification $publish):JsonResponse{$this->reviewer($r);$d=$r->validate(['approve'=>'required|boolean','reason'=>'nullable|string|max:2000']);$v=$action->execute($verification,$r->user(),(bool)$d['approve'],$d['reason']??null);$publish->execute($v->user,'security','kyc.'.$v->status->value,$v->status===KycVerificationStatus::Approved?'Verification approved':'Verification update',$v->status===KycVerificationStatus::Approved?'Your '.str_replace('_',' ',$v->type->value).' verification was approved.':'Your '.str_replace('_',' ',$v->type->value).' verification was rejected. Review the reason and submit updated documents.','kyc-review:'.$v->public_id.':'.$v->status->value,'/account/verification','kyc',$v->public_id,['status'=>$v->status->value,'expiresAt'=>$v->expires_at?->toIso8601String()],true);return response()->json(['data'=>['id'=>$v->public_id,'status'=>$v->status->value,'reviewedAt'=>$v->reviewed_at?->toIso8601String(),'expiresAt'=>$v->expires_at?->toIso8601String()]]);}

 /** Handles retry kyc for the admin compliance controller workflow. */
 public function retryKyc(Request $r,KycVerification $verification,KycLifecycleService $lifecycle):JsonResponse{$this->reviewer($r);$v=$lifecycle->retry($verification);return response()->json(['data'=>['id'=>$v->public_id,'status'=>$v->status->value,'providerAttempts'=>(int)$v->provider_attempts,'providerReference'=>$v->provider_reference,'providerLastError'=>$v->provider_last_error]]);}
 /** Handles sync kyc for the admin compliance controller workflow. */
 public function syncKyc(Request $r,KycVerification $verification,KycLifecycleService $lifecycle):JsonResponse{$this->reviewer($r);$v=$lifecycle->sync($verification);return response()->json(['data'=>['id'=>$v->public_id,'status'=>$v->status->value,'providerLastSyncAt'=>$v->provider_last_sync_at?->toIso8601String(),'providerLastError'=>$v->provider_last_error]]);}
 /** Handles events for the admin compliance controller workflow. */
 public function events(Request $r):JsonResponse{$this->admin($r);$rows=SecurityEvent::query()->with('user:id,name,email')->latest('id')->limit(200)->get();return response()->json(['data'=>$rows->map(/** Inline callback for this operation. */ fn($e)=>['id'=>$e->public_id,'user'=>$e->user?->email,'type'=>$e->type,'severity'=>$e->severity->value,'ip'=>$e->ip_address,'createdAt'=>$e->created_at?->toIso8601String(),'metadata'=>$e->metadata])->all()]);}
 /** Handles audits for the admin compliance controller workflow. */
 public function audits(Request $r):JsonResponse{$this->admin($r);$rows=AdminAuditLog::query()->with('actor:id,name,email')->latest('id')->limit(200)->get();return response()->json(['data'=>$rows->map(/** Inline callback for this operation. */ fn($a)=>['id'=>$a->public_id,'actor'=>$a->actor?->email,'action'=>$a->action,'method'=>$a->method,'path'=>$a->path,'status'=>$a->response_status,'targetType'=>$a->target_type,'targetId'=>$a->target_id,'ip'=>$a->ip_address,'createdAt'=>$a->created_at?->toIso8601String()])->all()]);}
 /** Handles reviewer for the admin compliance controller workflow. */
 private function reviewer(Request $r):void{$role=$r->user()?->role;$v=$role instanceof UserRole?$role->value:(string)$role;abort_unless(in_array($v,[UserRole::Moderator->value,UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);} private function admin(Request $r):void{$role=$r->user()?->role;$v=$role instanceof UserRole?$role->value:(string)$role;abort_unless(in_array($v,[UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);}
}
