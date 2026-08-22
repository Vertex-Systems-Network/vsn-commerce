<?php
namespace App\Models;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the KycVerification class and its project responsibilities. */
class KycVerification extends Model {
 /** Returns route key name. */
 public function getRouteKeyName():string{return 'public_id';}
 protected $fillable=['public_id','user_id','type','status','provider','provider_reference','provider_attempts','provider_last_attempt_at','provider_last_sync_at','next_provider_retry_at','provider_last_error','document_number_cipher','document_number_last4','country_code','document_front_path','document_back_path','selfie_path','address_proof_path','provider_payload','rejection_reason','submitted_at','reviewed_by_user_id','reviewed_at','expires_at'];
 /** Handles casts for the kyc verification workflow. */
 protected function casts():array{return ['type'=>KycVerificationType::class,'status'=>KycVerificationStatus::class,'document_number_cipher'=>'encrypted','provider_payload'=>'array','submitted_at'=>'datetime','reviewed_at'=>'datetime','expires_at'=>'datetime','provider_last_attempt_at'=>'datetime','provider_last_sync_at'=>'datetime','next_provider_retry_at'=>'datetime'];}
 /** Handles user for the kyc verification workflow. */
 public function user():BelongsTo{return $this->belongsTo(User::class);} public function reviewedBy():BelongsTo{return $this->belongsTo(User::class,'reviewed_by_user_id');}
}
