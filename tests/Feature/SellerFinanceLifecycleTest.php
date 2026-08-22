<?php
namespace Tests\Feature;

use App\Domain\Finance\Actions\PostOrderFinance;
use App\Domain\Finance\Actions\ReconcileVendorSettlements;
use App\Enums\CartStatus;
use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayoutMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the SellerFinanceLifecycleTest class and its project responsibilities. */
class SellerFinanceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies seller payout method is masked and requires finance verification. */
    public function test_seller_payout_method_is_masked_and_requires_finance_verification():void
    {
        $seller=User::factory()->create(['role'=>UserRole::Seller,'password'=>Hash::make('secret-pass')]);
        $vendor=Vendor::create(['owner_user_id'=>$seller->id,'name'=>'AL Seller','slug'=>'al-seller','status'=>'active','commission_bps'=>1000]);
        Sanctum::actingAs($seller);
        $method=$this->postJson('/api/v1/vendor/payout-methods',['password'=>'secret-pass','accountHolderName'=>'AL Seller','bankName'=>'AL Bank','accountIdentifier'=>'PK00ALBANK00001234','routingIdentifier'=>'00112233','countryCode'=>'PK','currency'=>'PKR'])->assertCreated()->assertJsonPath('data.accountLast4','1234')->assertJsonMissing(['accountIdentifier'=>'PK00ALBANK00001234'])->json('data');
        $this->assertDatabaseHas('vendor_payout_methods',['vendor_id'=>$vendor->id,'account_last4'=>'1234','verified_at'=>null]);
        $finance=User::factory()->create(['role'=>UserRole::Finance]);Sanctum::actingAs($finance);
        $this->postJson("/api/v1/admin/finance/payout-methods/{$method['id']}/verify",['verified'=>true])->assertOk()->assertJsonPath('data.verified',true);
    }

    /** Verifies failed payout keeps reservation and retry creates new attempt. */
    public function test_failed_payout_keeps_reservation_and_retry_creates_new_attempt():void
    {
        [$seller,$vendor,$order]=$this->matureOrder();Sanctum::actingAs($seller);
        $p=$this->postJson('/api/v1/vendor/payouts',['idempotencyKey'=>'al-fail-retry'])->assertOk()->json('data');
        $reserved=(int)$order->vendorOrders()->first()->fresh()->payout_reserved_minor;$this->assertGreaterThan(0,$reserved);
        $finance=User::factory()->create(['role'=>UserRole::Finance]);Sanctum::actingAs($finance);
        $this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/review",['approve'=>true])->assertOk();
        $this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/fail",['code'=>'bank_rejected','message'=>'Destination rejected transfer'])->assertOk()->assertJsonPath('data.status','failed');
        $this->assertSame($reserved,(int)$order->vendorOrders()->first()->fresh()->payout_reserved_minor);
        $this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/retry",[])->assertOk()->assertJsonPath('data.attemptNo',2);
        $this->assertDatabaseHas('vendor_payout_attempts',['vendor_payout_id'=>\App\Models\VendorPayout::where('public_id',$p['id'])->value('id'),'attempt_no'=>1,'status'=>'failed']);
        $this->assertDatabaseHas('vendor_payout_attempts',['vendor_payout_id'=>\App\Models\VendorPayout::where('public_id',$p['id'])->value('id'),'attempt_no'=>2,'status'=>'processing']);
    }

    /** Verifies cancelling failed payout releases reserved earnings. */
    public function test_cancelling_failed_payout_releases_reserved_earnings():void
    {
        [$seller,$vendor,$order]=$this->matureOrder();Sanctum::actingAs($seller);$p=$this->postJson('/api/v1/vendor/payouts',['idempotencyKey'=>'al-cancel'])->assertOk()->json('data');
        $finance=User::factory()->create(['role'=>UserRole::Finance]);Sanctum::actingAs($finance);$this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/review",['approve'=>true]);$this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/fail",['code'=>'provider_down','message'=>'Provider unavailable']);
        $this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/cancel",['note'=>'Investigate payout destination'])->assertOk()->assertJsonPath('data.status','cancelled');
        $this->assertSame(0,(int)$order->vendorOrders()->first()->fresh()->payout_reserved_minor);
    }

    /** Verifies batch becomes partial failed and returns to processing on retry. */
    public function test_batch_becomes_partial_failed_and_returns_to_processing_on_retry():void
    {
        [$seller,$vendor]=$this->matureOrder();Sanctum::actingAs($seller);$p=$this->postJson('/api/v1/vendor/payouts',['idempotencyKey'=>'al-batch'])->assertOk()->json('data');
        $finance=User::factory()->create(['role'=>UserRole::Finance]);Sanctum::actingAs($finance);$this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/review",['approve'=>true]);$b=$this->postJson('/api/v1/admin/finance/payout-batches',['payoutIds'=>[$p['id']]])->assertOk()->json('data');
        $this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/fail",['code'=>'bank_timeout','message'=>'Transfer timed out'])->assertOk();$this->assertDatabaseHas('vendor_payout_batches',['public_id'=>$b['id'],'status'=>'partial_failed']);
        $this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/retry",[])->assertOk();$this->assertDatabaseHas('vendor_payout_batches',['public_id'=>$b['id'],'status'=>'processing']);
    }

    /** Verifies seller finance summary exposes payout readiness and hold policy. */
    public function test_seller_finance_summary_exposes_payout_readiness_and_hold_policy():void
    {
        [$seller]=$this->matureOrder();Sanctum::actingAs($seller);$this->getJson('/api/v1/vendor/finance')->assertOk()->assertJsonPath('data.payoutReady',true)->assertJsonPath('data.minimumPayoutMinor',(int)config('vsn.finance.minimum_payout_minor'))->assertJsonStructure(['data'=>['holdBreakdown'=>['paymentMinor','deliveryMinor','returnWindowMinor'],'sellerRecoveryOutstandingMinor','defaultPayoutMethod']]);
    }

    /** Handles mature order for the milestone alseller finance lifecycle test workflow. */
    private function matureOrder():array
    {
        config()->set('vsn.security.seller_payout_requires_phone',false);config()->set('vsn.security.seller_payout_requires_identity',false);config()->set('vsn.finance.require_verified_payout_method',true);
        $seller=User::factory()->create(['role'=>UserRole::Seller]);$vendor=Vendor::create(['owner_user_id'=>$seller->id,'name'=>'AL Finance Seller','slug'=>'al-'.Str::lower((string)Str::ulid()),'status'=>'active','commission_bps'=>1000]);
        VendorPayoutMethod::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'type'=>'bank_transfer','label'=>'Verified bank','account_holder_name'=>'AL Seller','bank_name'=>'AL Bank','account_identifier_cipher'=>'PK00AL00001234','account_last4'=>'1234','country_code'=>'PK','currency'=>'PKR','is_default'=>true,'verified_at'=>now()]);
        $buyer=User::factory()->create();$cart=Cart::create(['public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'status'=>CartStatus::Converted,'currency'=>'PKR']);$session=CheckoutSession::create(['public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'cart_id'=>$cart->id,'idempotency_key'=>'al-'.Str::uuid(),'status'=>CheckoutStatus::Converted,'currency'=>'PKR','address_snapshot'=>['recipient_name'=>$buyer->name,'phone'=>'0300','line1'=>'Test','city'=>'Lahore','country_code'=>'PK'],'shipping_method'=>'standard','payment_method'=>'card','subtotal_minor'=>200000,'shipping_minor'=>10000,'discount_minor'=>0,'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>210000,'expires_at'=>now()->addMinute(),'converted_at'=>now()]);
        $order=Order::create(['public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'checkout_session_id'=>$session->id,'status'=>OrderStatus::Delivered,'payment_status'=>PaymentStatus::Paid,'payment_method'=>'card','currency'=>'PKR','subtotal_minor'=>200000,'shipping_minor'=>10000,'discount_minor'=>0,'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>210000,'placed_at'=>now()->subDays(40),'delivered_at'=>now()->subDays(31)]);$order->vendorOrders()->create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'status'=>OrderStatus::Delivered,'currency'=>'PKR','subtotal_minor'=>200000,'shipping_minor'=>10000,'discount_minor'=>0,'total_minor'=>210000,'commission_bps'=>1000,'platform_commission_minor'=>20000,'seller_payable_minor'=>190000,'delivered_at'=>now()->subDays(31)]);app(PostOrderFinance::class)->execute($order);app(ReconcileVendorSettlements::class)->execute($vendor->id);return [$seller,$vendor,$order];
    }
}
