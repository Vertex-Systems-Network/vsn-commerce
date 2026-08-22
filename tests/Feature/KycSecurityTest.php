<?php
namespace Tests\Feature;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Enums\UserRole;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
/** Defines the KycSecurityTest class and its project responsibilities. */
class KycSecurityTest extends TestCase { use RefreshDatabase;
 /** Handles user for the kyc security test workflow. */
 private function user(string $role='customer'):User{$u=User::factory()->create(['role'=>$role]);$u->profile()->create();return $u;}
 /** Verifies government id submission is pending and private. */
 public function test_government_id_submission_is_pending_and_private():void{Storage::fake('local');$u=$this->user();$this->actingAs($u)->post('/api/v1/kyc',['type'=>'government_id','document_number'=>'42101-1234567-1','country_code'=>'PK','document_front'=>UploadedFile::fake()->image('id.jpg')])->assertCreated()->assertJsonPath('data.status','pending')->assertJsonMissing(['42101-1234567-1']);$this->assertDatabaseHas('kyc_verifications',['user_id'=>$u->id,'document_number_last4'=>'5671']);}
 /** Verifies duplicate pending kyc is rejected. */
 public function test_duplicate_pending_kyc_is_rejected():void{Storage::fake('local');$u=$this->user();KycVerification::create(['public_id'=>(string)\Illuminate\Support\Str::uuid(),'user_id'=>$u->id,'type'=>KycVerificationType::GovernmentId,'status'=>KycVerificationStatus::Pending,'provider'=>'manual','submitted_at'=>now()]);$this->actingAs($u)->post('/api/v1/kyc',['type'=>'government_id','document_number'=>'1234','document_front'=>UploadedFile::fake()->image('id.jpg')])->assertStatus(409);}
 /** Verifies customer cannot review kyc. */
 public function test_customer_cannot_review_kyc():void{$u=$this->user();$v=KycVerification::create(['public_id'=>(string)\Illuminate\Support\Str::uuid(),'user_id'=>$u->id,'type'=>KycVerificationType::GovernmentId,'status'=>KycVerificationStatus::Pending,'provider'=>'manual','submitted_at'=>now()]);$this->actingAs($u)->post("/api/v1/admin/compliance/kyc/{$v->public_id}/review",['approve'=>true])->assertForbidden();}
 /** Verifies admin can approve kyc. */
 public function test_admin_can_approve_kyc():void{$u=$this->user();$a=$this->user(UserRole::Admin->value);$v=KycVerification::create(['public_id'=>(string)\Illuminate\Support\Str::uuid(),'user_id'=>$u->id,'type'=>KycVerificationType::GovernmentId,'status'=>KycVerificationStatus::Pending,'provider'=>'manual','submitted_at'=>now()]);$this->actingAs($a)->post("/api/v1/admin/compliance/kyc/{$v->public_id}/review",['approve'=>true])->assertOk()->assertJsonPath('data.status','approved');}
 /** Verifies phone sandbox is disabled by default. */
 public function test_phone_sandbox_is_disabled_by_default():void{$u=$this->user();$this->actingAs($u)->post('/api/v1/profile/phone/send-code',['phone'=>'+923001234567'])->assertStatus(503);}
 /** Verifies security endpoint registers a device. */
 public function test_security_endpoint_registers_a_device():void{$u=$this->user();$this->actingAs($u)->withHeader('X-Device-Id','browser-a')->get('/api/v1/security')->assertOk()->assertJsonCount(1,'data.devices');}
 /** Verifies user cannot revoke another users device. */
 public function test_user_cannot_revoke_another_users_device():void{$a=$this->user();$b=$this->user();$d=$b->devices()->create(['device_key_hash'=>hash('sha256','x'),'first_seen_at'=>now(),'last_seen_at'=>now()]);$this->actingAs($a)->post("/api/v1/security/devices/{$d->id}/revoke")->assertForbidden();}
 /** Verifies payout security gate rejects unverified seller before finance reconciliation. */
 public function test_payout_security_gate_rejects_unverified_seller_before_finance_reconciliation():void{config()->set('vsn.security.seller_payout_requires_phone',true);config()->set('vsn.security.seller_payout_requires_identity',true);$u=$this->user(UserRole::Seller->value);$vendor=\App\Models\Vendor::create(['owner_user_id'=>$u->id,'name'=>'Secure Seller','slug'=>'secure-seller','status'=>'active','commission_bps'=>1000]);$this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);app(\App\Domain\Finance\Actions\RequestVendorPayout::class)->execute($u,$vendor,'kyc-gate-test');}
 /** Verifies admin mutation creates immutable audit record. */
 public function test_admin_mutation_creates_immutable_audit_record():void{$u=$this->user();$a=$this->user(UserRole::Admin->value);$v=KycVerification::create(['public_id'=>(string)\Illuminate\Support\Str::uuid(),'user_id'=>$u->id,'type'=>KycVerificationType::GovernmentId,'status'=>KycVerificationStatus::Pending,'provider'=>'manual','submitted_at'=>now()]);$this->actingAs($a)->post("/api/v1/admin/compliance/kyc/{$v->public_id}/review",['approve'=>true])->assertOk();$this->assertDatabaseHas('admin_audit_logs',['actor_user_id'=>$a->id,'method'=>'POST']);$this->assertGreaterThanOrEqual(1,\App\Models\AdminAuditLog::count());}
}
