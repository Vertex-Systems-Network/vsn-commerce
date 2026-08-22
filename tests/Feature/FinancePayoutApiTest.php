<?php

namespace Tests\Feature;

use App\Domain\Finance\Actions\PostOrderFinance;
use App\Domain\Finance\Actions\PostRefundFinance;
use App\Domain\Finance\Actions\ReconcileVendorSettlements;
use App\Enums\CartStatus;
use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\ReturnRequestStatus;
use App\Enums\ReturnResolution;
use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\FinanceEntry;
use App\Models\FinanceJournal;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayoutMethod;
use App\Models\VendorRefundAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the FinancePayoutApiTest class and its project responsibilities. */
class FinancePayoutApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies review coupon is platform funded and finance journal balances. */
    public function test_review_coupon_is_platform_funded_and_finance_journal_balances(): void
    {
        [$owner,$vendor,$order,$vo]=$this->order(payment: PaymentStatus::Paid, delivered: true, discount: 10_000);
        app(PostOrderFinance::class)->execute($order);
        $vo=$vo->fresh();
        $this->assertSame(100_000,(int)$vo->seller_payable_minor); // 100k merchandise + 10k shipping - 10k commission
        $this->assertSame(10_000,(int)$vo->coupon_subsidy_minor);
        $journal=FinanceJournal::where('idempotency_key',"finance-order:{$order->public_id}")->with('entries')->firstOrFail();
        $this->assertSame((int)$journal->entries->where('direction','debit')->sum('amount_minor'),(int)$journal->entries->where('direction','credit')->sum('amount_minor'));
        $this->assertDatabaseHas('finance_entries',['account_code'=>'expense.review_coupon_subsidy','direction'=>'debit','amount_minor'=>10_000]);
    }

    /** Verifies cod stays receivable until finance confirms collection. */
    public function test_cod_stays_receivable_until_finance_confirms_collection(): void
    {
        [$owner,$vendor,$order]=$this->order(payment: PaymentStatus::Pending, delivered: true, method:'cod');
        app(PostOrderFinance::class)->execute($order);
        $this->assertDatabaseHas('finance_entries',['account_code'=>'asset.cod_receivable','direction'=>'debit','amount_minor'=>$order->total_minor]);
        $finance=User::factory()->create(['role'=>UserRole::Finance]); Sanctum::actingAs($finance);
        $this->postJson("/api/v1/admin/finance/orders/{$order->public_id}/confirm-cod")->assertOk()->assertJsonPath('data.paymentStatus','paid');
        $this->assertDatabaseHas('finance_journals',['idempotency_key'=>"finance-cod-collection:{$order->public_id}"]);
    }

    /** Verifies settlement waits for payment delivery and hold window. */
    public function test_settlement_waits_for_payment_delivery_and_hold_window(): void
    {
        [$owner,$vendor,$order]=$this->order(payment:PaymentStatus::Pending,delivered:false);
        app(PostOrderFinance::class)->execute($order); app(ReconcileVendorSettlements::class)->execute($vendor->id);
        $this->assertDatabaseHas('vendor_settlements',['vendor_id'=>$vendor->id,'status'=>'hold_payment']);
        $order->update(['payment_status'=>PaymentStatus::Paid]);app(ReconcileVendorSettlements::class)->execute($vendor->id);
        $this->assertDatabaseHas('vendor_settlements',['vendor_id'=>$vendor->id,'status'=>'hold_delivery']);
        $order->update(['status'=>OrderStatus::Delivered,'delivered_at'=>now()->subDays(31)]);app(ReconcileVendorSettlements::class)->execute($vendor->id);
        $this->assertDatabaseHas('vendor_settlements',['vendor_id'=>$vendor->id,'status'=>'available']);
    }

    /** Verifies seller can request available payout and retry is idempotent. */
    public function test_seller_can_request_available_payout_and_retry_is_idempotent(): void
    {
        [$owner,$vendor,$order]=$this->order(payment:PaymentStatus::Paid,delivered:true,deliveredAt:now()->subDays(31));app(PostOrderFinance::class)->execute($order);app(ReconcileVendorSettlements::class)->execute($vendor->id);Sanctum::actingAs($owner);
        $payload=['idempotencyKey'=>'seller-payout-001'];
        $first=$this->postJson('/api/v1/vendor/payouts',$payload)->assertOk()->json('data.id');
        $second=$this->postJson('/api/v1/vendor/payouts',$payload)->assertOk()->json('data.id');
        $this->assertSame($first,$second);$this->assertDatabaseCount('vendor_payouts',1);$this->assertGreaterThan(0,(int)$order->vendorOrders()->first()->fresh()->payout_reserved_minor);
    }

    /** Verifies seller cannot request more than available balance. */
    public function test_seller_cannot_request_more_than_available_balance(): void
    {
        [$owner,$vendor,$order]=$this->order(payment:PaymentStatus::Paid,delivered:true,deliveredAt:now()->subDays(31));app(PostOrderFinance::class)->execute($order);app(ReconcileVendorSettlements::class)->execute($vendor->id);Sanctum::actingAs($owner);
        $this->postJson('/api/v1/vendor/payouts',['idempotencyKey'=>'seller-payout-too-high','amountMinor'=>999_999_999])->assertStatus(422);
    }

    /** Verifies finance approval and paid confirmation posts payout journal. */
    public function test_finance_approval_and_paid_confirmation_posts_payout_journal(): void
    {
        [$owner,$vendor,$order]=$this->order(payment:PaymentStatus::Paid,delivered:true,deliveredAt:now()->subDays(31));app(PostOrderFinance::class)->execute($order);app(ReconcileVendorSettlements::class)->execute($vendor->id);Sanctum::actingAs($owner);
        $p=$this->postJson('/api/v1/vendor/payouts',['idempotencyKey'=>'seller-payout-paid'])->assertOk()->json('data');
        $finance=User::factory()->create(['role'=>UserRole::Finance]);Sanctum::actingAs($finance);
        $this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/review",['approve'=>true])->assertOk()->assertJsonPath('data.status','approved');
        $this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/paid",['providerReference'=>'BANK-0001'])->assertOk()->assertJsonPath('data.status','paid');
        $this->assertDatabaseHas('finance_journals',['idempotency_key'=>"finance-payout:{$p['id']}"]);
        $this->assertSame(0,(int)$order->vendorOrders()->first()->fresh()->payout_reserved_minor);
    }

    /** Verifies finance can batch approved payout and batch completes after payment. */
    public function test_finance_can_batch_approved_payout_and_batch_completes_after_payment(): void
    {
        [$owner,$vendor,$order]=$this->order(payment:PaymentStatus::Paid,delivered:true,deliveredAt:now()->subDays(31));
        app(PostOrderFinance::class)->execute($order);
        app(ReconcileVendorSettlements::class)->execute($vendor->id);
        Sanctum::actingAs($owner);
        $payout=$this->postJson('/api/v1/vendor/payouts',['idempotencyKey'=>'seller-payout-batch-001'])->assertOk()->json('data');

        $finance=User::factory()->create(['role'=>UserRole::Finance]);
        Sanctum::actingAs($finance);
        $this->postJson("/api/v1/admin/finance/payouts/{$payout['id']}/review",['approve'=>true])->assertOk()->assertJsonPath('data.status','approved');
        $batch=$this->postJson('/api/v1/admin/finance/payout-batches',['payoutIds'=>[$payout['id']],'providerBatchReference'=>'BANK-BATCH-001'])->assertOk()->assertJsonPath('data.status','processing')->json('data');
        $this->assertDatabaseHas('vendor_payouts',['public_id'=>$payout['id'],'status'=>'processing']);
        $this->assertDatabaseHas('vendor_payout_batches',['public_id'=>$batch['id'],'status'=>'processing']);

        $this->postJson("/api/v1/admin/finance/payouts/{$payout['id']}/paid",['providerReference'=>'BANK-BATCH-ITEM-001'])->assertOk()->assertJsonPath('data.status','paid');
        $this->assertDatabaseHas('vendor_payout_batches',['public_id'=>$batch['id'],'status'=>'completed']);
    }

    /** Verifies refund after paid payout creates seller recovery receivable. */
    public function test_refund_after_paid_payout_creates_seller_recovery_receivable(): void
    {
        [$owner,$vendor,$order,$vo]=$this->order(payment:PaymentStatus::Paid,delivered:true,deliveredAt:now()->subDays(31),discount:0);app(PostOrderFinance::class)->execute($order);app(ReconcileVendorSettlements::class)->execute($vendor->id);Sanctum::actingAs($owner);
        $p=$this->postJson('/api/v1/vendor/payouts',['idempotencyKey'=>'seller-payout-before-refund'])->assertOk()->json('data');$finance=User::factory()->create(['role'=>UserRole::Finance]);Sanctum::actingAs($finance);$this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/review",['approve'=>true])->assertOk();$this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/paid",['providerReference'=>'BANK-REFUND-CASE'])->assertOk();
        $refund=$this->refund($order,$vo,100_000,90_000,10_000,0);app(PostRefundFinance::class)->execute($refund);
        $this->assertDatabaseHas('finance_entries',['account_code'=>'asset.seller_recovery','direction'=>'debit','vendor_id'=>$vendor->id,'amount_minor'=>90_000]);
    }

    /** Verifies future mature settlement offsets seller recovery before new payout. */
    public function test_future_mature_settlement_offsets_seller_recovery_before_new_payout(): void
    {
        [$owner,$vendor,$order,$vo]=$this->order(payment:PaymentStatus::Paid,delivered:true,deliveredAt:now()->subDays(31));app(PostOrderFinance::class)->execute($order);app(ReconcileVendorSettlements::class)->execute($vendor->id);Sanctum::actingAs($owner);$p=$this->postJson('/api/v1/vendor/payouts',['idempotencyKey'=>'paid-before-recovery'])->assertOk()->json('data');$finance=User::factory()->create(['role'=>UserRole::Finance]);Sanctum::actingAs($finance);$this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/review",['approve'=>true]);$this->postJson("/api/v1/admin/finance/payouts/{$p['id']}/paid",['providerReference'=>'BANK-RECOVERY-1']);app(PostRefundFinance::class)->execute($this->refund($order,$vo,100_000,90_000,10_000,0));
        [$owner2,$vendor2,$newOrder]=$this->order(payment:PaymentStatus::Paid,delivered:true,deliveredAt:now()->subDays(31),owner:$owner,vendor:$vendor);app(PostOrderFinance::class)->execute($newOrder);app(ReconcileVendorSettlements::class)->execute($vendor->id);
        $this->assertDatabaseHas('finance_entries',['account_code'=>'asset.seller_recovery','direction'=>'credit','vendor_id'=>$vendor->id]);
    }

    /** Verifies posted finance entries are immutable. */
    public function test_posted_finance_entries_are_immutable(): void
    {
        [$owner,$vendor,$order]=$this->order(payment:PaymentStatus::Paid,delivered:true);app(PostOrderFinance::class)->execute($order);$entry=FinanceEntry::firstOrFail();$this->expectException(\LogicException::class);$entry->update(['amount_minor'=>$entry->amount_minor+1]);
    }

    /** Handles order for the finance payout api test workflow. */
    private function order(PaymentStatus $payment=PaymentStatus::Paid,bool $delivered=true,string $method='card',int $discount=0,$deliveredAt=null,?User $owner=null,?Vendor $vendor=null):array
    {
        $owner=$owner?:User::factory()->create(['role'=>UserRole::Seller]);$vendor=$vendor?:Vendor::create(['owner_user_id'=>$owner->id,'name'=>'Finance Seller','slug'=>'finance-'.Str::lower((string)Str::ulid()),'status'=>'active','commission_bps'=>1000]);
        VendorPayoutMethod::firstOrCreate(['vendor_id'=>$vendor->id,'account_last4'=>'1234'],['public_id'=>(string)Str::ulid(),'type'=>'bank_transfer','label'=>'Test bank','account_holder_name'=>'Finance Seller','bank_name'=>'Test Bank','account_identifier_cipher'=>'PK00TEST00001234','account_last4'=>'1234','country_code'=>'PK','currency'=>'PKR','is_default'=>true,'verified_at'=>now()]);
        $buyer=User::factory()->create();
        $cart=Cart::create(['public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'status'=>CartStatus::Converted,'currency'=>'PKR']);
        $session=CheckoutSession::create(['public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'cart_id'=>$cart->id,'idempotency_key'=>'fin-'.Str::uuid(),'status'=>CheckoutStatus::Converted,'currency'=>'PKR','address_snapshot'=>['recipient_name'=>$buyer->name,'phone'=>'0300','line1'=>'Test','city'=>'Lahore','country_code'=>'PK'],'shipping_method'=>'standard','payment_method'=>$method,'subtotal_minor'=>100_000,'shipping_minor'=>10_000,'discount_minor'=>$discount,'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>110_000-$discount,'expires_at'=>now()->addMinute(),'converted_at'=>now()]);
        $order=Order::create(['public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'checkout_session_id'=>$session->id,'status'=>$delivered?OrderStatus::Delivered:OrderStatus::Confirmed,'payment_status'=>$payment,'payment_method'=>$method,'currency'=>'PKR','subtotal_minor'=>100_000,'shipping_minor'=>10_000,'discount_minor'=>$discount,'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>110_000-$discount,'placed_at'=>now()->subDays(40),'delivered_at'=>$delivered?($deliveredAt?:now()):null]);
        $vo=$order->vendorOrders()->create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'status'=>$order->status,'currency'=>'PKR','subtotal_minor'=>100_000,'shipping_minor'=>10_000,'discount_minor'=>$discount,'total_minor'=>110_000-$discount,'commission_bps'=>1000,'platform_commission_minor'=>10_000,'seller_payable_minor'=>100_000-$discount]);
        return [$owner,$vendor,$order,$vo,$buyer];
    }

    /** Handles refund for the finance payout api test workflow. */
    private function refund(Order $order,$vo,int $amount,int $seller,int $commission,int $subsidy):Refund
    {
        $request=ReturnRequest::create(['public_id'=>(string)Str::ulid(),'user_id'=>$order->user_id,'order_id'=>$order->id,'status'=>ReturnRequestStatus::Received,'resolution'=>ReturnResolution::OriginalPayment,'reason'=>'Finance test refund','currency'=>'PKR','requested_minor'=>$amount,'approved_minor'=>$amount,'submitted_at'=>now(),'received_at'=>now()]);
        $refund=Refund::create(['public_id'=>(string)Str::ulid(),'return_request_id'=>$request->id,'order_id'=>$order->id,'status'=>RefundStatus::Completed,'resolution'=>ReturnResolution::OriginalPayment,'currency'=>'PKR','amount_minor'=>$amount,'cash_refund_minor'=>$amount,'coin_refund_minor'=>0,'coin_refund_coins'=>0,'idempotency_key'=>'finance-refund-test:'.Str::uuid(),'processed_at'=>now()]);
        VendorRefundAdjustment::create(['public_id'=>(string)Str::ulid(),'refund_id'=>$refund->id,'vendor_order_id'=>$vo->id,'refund_minor'=>$amount,'platform_commission_reversal_minor'=>$commission,'seller_payable_reversal_minor'=>$seller,'coupon_subsidy_reversal_minor'=>$subsidy]);
        $vo->increment('platform_commission_reversed_minor',$commission);$vo->increment('seller_payable_reversed_minor',$seller);$vo->increment('coupon_subsidy_reversed_minor',$subsidy);return $refund;
    }
}
