<?php
namespace Tests\Feature;
use App\Domain\Risk\Exceptions\RiskBlockedException;
use App\Domain\Risk\Services\{RiskEvaluator,RiskGate,RiskRecorder};
use App\Enums\UserRole;
use App\Models\{RiskCase,RiskEvent,RiskHold,RiskProfile,SavedPaymentMethod,User,UserDevice,Vendor};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;
/** Defines the RiskAbusePreventionTest class and its project responsibilities. */
class RiskAbusePreventionTest extends TestCase { use RefreshDatabase;
    /** Verifies risk schema exists. */
    public function test_risk_schema_exists():void{$this->assertTrue(\Schema::hasTable('risk_profiles'));$this->assertTrue(\Schema::hasTable('risk_events'));$this->assertTrue(\Schema::hasTable('risk_cases'));$this->assertTrue(\Schema::hasTable('risk_holds'));}
    /** Verifies risk evidence model is immutable. */
    public function test_risk_evidence_model_is_immutable():void{$u=User::factory()->create();$e=app(RiskRecorder::class)->record($u,null,'test_signal','medium',10);$this->expectException(LogicException::class);$e->update(['score_delta'=>99]);}
    /** Verifies scoped hold blocks only the sensitive scope. */
    public function test_scoped_hold_blocks_only_the_sensitive_scope():void{$u=User::factory()->create();RiskHold::create(['public_id'=>(string)Str::ulid(),'user_id'=>$u->id,'scope'=>'wallet','status'=>'active','reason'=>'Manual review','starts_at'=>now()]);$gate=app(RiskGate::class);$gate->assertAllowed($u,'games');$this->expectException(RiskBlockedException::class);$gate->assertAllowed($u,'wallet');}
    /** Verifies expired hold does not block. */
    public function test_expired_hold_does_not_block():void{$u=User::factory()->create();RiskHold::create(['public_id'=>(string)Str::ulid(),'user_id'=>$u->id,'scope'=>'payments','status'=>'active','reason'=>'Temporary','starts_at'=>now()->subHour(),'expires_at'=>now()->subMinute()]);app(RiskGate::class)->assertAllowed($u,'payments');$this->assertDatabaseHas('risk_holds',['user_id'=>$u->id,'status'=>'expired']);}
    /** Verifies shared device accounts increase risk without storing raw device id. */
    public function test_shared_device_accounts_increase_risk_without_storing_raw_device_id():void{$u=User::factory()->create();$other=User::factory()->create();$hash=hash('sha256','device-secret');foreach([$u,$other] as $x)UserDevice::create(['user_id'=>$x->id,'device_key_hash'=>$hash,'label'=>'Chrome','first_seen_at'=>now(),'last_seen_at'=>now()]);$p=app(RiskEvaluator::class)->user($u,'test');$this->assertGreaterThanOrEqual(25,$p->score);$this->assertArrayHasKey('sharedDeviceAccounts',$p->signal_summary);$this->assertDatabaseMissing('user_devices',['device_key_hash'=>'device-secret']);}
    /** Verifies shared payment fingerprint is a multi account signal. */
    public function test_shared_payment_fingerprint_is_a_multi_account_signal():void{$u=User::factory()->create();$other=User::factory()->create();foreach([$u,$other] as $x)SavedPaymentMethod::create(['public_id'=>(string)Str::ulid(),'user_id'=>$x->id,'provider'=>'sandbox','payment_method'=>'card','provider_token_cipher'=>'tok-'.Str::random(8),'fingerprint_sha256'=>hash('sha256','same-card'),'brand'=>'visa','last4'=>'4242','status'=>'active']);$p=app(RiskEvaluator::class)->user($u,'test');$this->assertArrayHasKey('sharedPaymentInstrumentAccounts',$p->signal_summary);$this->assertGreaterThanOrEqual(25,$p->score);}
    /** Verifies high score opens one manual review case without automatic hold by default. */
    public function test_high_score_opens_one_manual_review_case_without_automatic_hold_by_default():void{config(['vsn.risk.review_score'=>50,'vsn.risk.auto_hold_critical'=>false]);$u=User::factory()->create();for($i=0;$i<3;$i++){UserDevice::create(['user_id'=>$i===0?$u->id:User::factory()->create()->id,'device_key_hash'=>hash('sha256','shared-device'),'label'=>'Browser','first_seen_at'=>now(),'last_seen_at'=>now()]);}foreach([$u,User::factory()->create()] as $x)SavedPaymentMethod::create(['public_id'=>(string)Str::ulid(),'user_id'=>$x->id,'provider'=>'sandbox','payment_method'=>'card','provider_token_cipher'=>'tok-'.Str::random(8),'fingerprint_sha256'=>hash('sha256','same-payment'),'brand'=>'visa','last4'=>'4242','status'=>'active']);$p=app(RiskEvaluator::class)->user($u,'test');$this->assertGreaterThanOrEqual(50,$p->score);$this->assertDatabaseCount('risk_cases',1);$this->assertDatabaseCount('risk_holds',0);app(RiskEvaluator::class)->user($u,'test-retry');$this->assertDatabaseCount('risk_cases',1);}
    /** Verifies wallet velocity limit is deterministic and configurable. */
    public function test_wallet_velocity_limit_is_deterministic_and_configurable():void{config(['vsn.risk.velocity.wallet_transfers_per_hour'=>0]);$u=User::factory()->create();$this->expectException(RiskBlockedException::class);app(RiskGate::class)->walletTransfer($u,70);}
    /** Verifies vendor scoped payout hold blocks seller payout scope. */
    public function test_vendor_scoped_payout_hold_blocks_seller_payout_scope():void{$u=User::factory()->create(['role'=>UserRole::Seller]);$v=Vendor::create(['owner_user_id'=>$u->id,'name'=>'Seller','slug'=>'seller-risk','status'=>'active']);RiskHold::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$v->id,'scope'=>'payouts','status'=>'active','reason'=>'Seller review','starts_at'=>now()]);$this->expectException(RiskBlockedException::class);app(RiskGate::class)->payout($u,$v);}
    /** Verifies customer cannot access admin risk center. */
    public function test_customer_cannot_access_admin_risk_center():void{$u=User::factory()->create(['role'=>UserRole::Customer]);$this->actingAs($u)->getJson('/api/v1/admin/risk')->assertForbidden();}
    /** Verifies admin can view risk center without exposing raw device or payment token. */
    public function test_admin_can_view_risk_center_without_exposing_raw_device_or_payment_token():void{$admin=User::factory()->create(['role'=>UserRole::Admin]);$u=User::factory()->create();RiskProfile::create(['public_id'=>(string)Str::ulid(),'user_id'=>$u->id,'score'=>25,'level'=>'medium','status'=>'monitored','signal_summary'=>['sharedDeviceAccounts'=>['value'=>1,'points'=>25]],'last_evaluated_at'=>now()]);$response=$this->actingAs($admin)->getJson('/api/v1/admin/risk')->assertOk();$json=$response->getContent();$this->assertStringNotContainsString('device_key_hash',$json);$this->assertStringNotContainsString('provider_token_cipher',$json);}
}
