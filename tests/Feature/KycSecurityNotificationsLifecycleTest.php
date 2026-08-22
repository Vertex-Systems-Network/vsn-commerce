<?php
namespace Tests\Feature;
use App\Domain\Kyc\Services\KycLifecycleService;
use App\Domain\Notifications\Actions\DispatchNotificationDeliveries;
use App\Domain\Security\Services\SecurityRecorder;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Enums\SecuritySeverity;
use App\Enums\UserRole;
use App\Models\KycVerification;
use App\Models\MobileApiSession;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;
/** Defines the KycSecurityNotificationsLifecycleTest class and its project responsibilities. */
class KycSecurityNotificationsLifecycleTest extends TestCase
{
 use RefreshDatabase;
 /** Handles user for the milestone ankyc security notifications test workflow. */
 private function user(string $role='customer'):User{$u=User::factory()->create(['role'=>$role,'email_verified_at'=>null]);$u->profile()->create();return $u;}
 /** Verifies authenticated user can verify email with otp. */
 public function test_authenticated_user_can_verify_email_with_otp():void{Notification::fake();$u=$this->user();$send=$this->actingAs($u)->postJson('/api/v1/profile/email/send-code')->assertOk();$code=$send->json('data.sandboxCode');$this->assertNotEmpty($code);$this->actingAs($u)->postJson('/api/v1/profile/email/verify',['code'=>$code])->assertOk()->assertJsonPath('data.verified',true);$this->assertNotNull($u->fresh()->email_verified_at);}
 /** Verifies critical security notification ignores disabled security preferences. */
 public function test_critical_security_notification_ignores_disabled_security_preferences():void{$u=$this->user();foreach(['in_app','email'] as $channel)NotificationPreference::create(['user_id'=>$u->id,'category'=>'security','channel'=>$channel,'enabled'=>false]);$request=Request::create('/api/v1/login','POST');$request->server->set('REMOTE_ADDR','10.10.10.10');app(SecurityRecorder::class)->record($u,'new_device_login',SecuritySeverity::Medium,$request);$this->assertDatabaseHas('marketplace_notifications',['user_id'=>$u->id,'category'=>'security','in_app_visible'=>true]);$this->assertDatabaseHas('notification_deliveries',['channel'=>'email','status'=>'pending']);}
 /** Verifies revoke other sessions revokes mobile sessions. */
 public function test_revoke_other_sessions_revokes_mobile_sessions():void{$u=$this->user();$u->forceFill(['password'=>'ChangeMe12345'])->save();MobileApiSession::create(['public_id'=>(string)Str::ulid(),'user_id'=>$u->id,'device_key_hash'=>hash('sha256','phone-a'),'device_name'=>'Phone A','platform'=>'android','refresh_token_hash'=>hash('sha256','refresh-a'),'refresh_expires_at'=>now()->addDays(30),'last_seen_at'=>now()]);$this->actingAs($u)->postJson('/api/v1/security/sessions/revoke-others',['password'=>'ChangeMe12345'])->assertOk()->assertJsonPath('data.mobileSessions',1);$this->assertNotNull(MobileApiSession::first()->revoked_at);}
 /** Verifies kyc lifecycle expires approved verification. */
 public function test_kyc_lifecycle_expires_approved_verification():void{$u=$this->user();$v=KycVerification::create(['public_id'=>(string)Str::uuid(),'user_id'=>$u->id,'type'=>KycVerificationType::GovernmentId,'status'=>KycVerificationStatus::Approved,'provider'=>'manual','submitted_at'=>now()->subYear(),'reviewed_at'=>now()->subYear(),'expires_at'=>now()->subMinute()]);$result=app(KycLifecycleService::class)->reconcile();$this->assertSame(1,$result['expired']);$this->assertSame(KycVerificationStatus::Expired,$v->fresh()->status);}
 /** Verifies failed notification delivery has attempt evidence and admin can requeue. */
 public function test_failed_notification_delivery_has_attempt_evidence_and_admin_can_requeue():void{Notification::fake();config()->set('vsn.notifications.email_provider','resend');config()->set('vsn.notifications.providers.resend.api_key','bad');config()->set('vsn.notifications.providers.resend.from','bad');config()->set('vsn.notifications.max_attempts',1);$u=$this->user();$n=app(\App\Domain\Notifications\Actions\PublishMarketplaceNotification::class)->execute($u,'orders','order.test','Order','Body','an-fail');app(DispatchNotificationDeliveries::class)->execute();$delivery=NotificationDelivery::where('marketplace_notification_id',$n->id)->where('channel','email')->firstOrFail();$this->assertSame('failed',$delivery->status);$this->assertDatabaseHas('notification_delivery_attempts',['notification_delivery_id'=>$delivery->id,'attempt_number'=>1,'status'=>'failed']);$admin=$this->user(UserRole::Admin->value);$this->actingAs($admin)->postJson("/api/v1/admin/notifications/deliveries/{$delivery->id}/retry")->assertOk()->assertJsonPath('data.status','pending');}
}
