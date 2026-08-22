<?php
namespace Tests\Feature;

use App\Domain\Affiliate\Actions\CreditAvailableAffiliateCommissions;
use App\Domain\Returns\Actions\ProcessRefund;
use App\Enums\AffiliateCommissionStatus;
use App\Enums\CartStatus;
use App\Enums\CheckoutStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\AffiliateCommission;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the ReturnsRefundsApiTest class and its project responsibilities. */
class ReturnsRefundsApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies customer can create partial line item return only for delivered order. */
    public function test_customer_can_create_partial_line_item_return_only_for_delivered_order(): void
    {
        [$user,$order,$item]=$this->order('cod',2,100_000,0);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/returns',['orderId'=>$order->public_id,'reason'=>'Item damaged','resolution'=>'refund_original','items'=>[['orderItemId'=>$item->id,'quantity'=>1]]])
            ->assertOk()->assertJsonPath('data.items.0.quantity',1)->assertJsonPath('data.requestedMinor',50_000);
        $this->assertDatabaseHas('return_request_items',['order_item_id'=>$item->id,'quantity'=>1,'requested_minor'=>50_000]);
    }

    /** Verifies return is rejected before delivery. */
    public function test_return_is_rejected_before_delivery(): void
    {
        [$user,$order,$item]=$this->order('cod',1,100_000,0); $order->update(['status'=>OrderStatus::Confirmed]); Sanctum::actingAs($user);
        $this->postJson('/api/v1/returns',['orderId'=>$order->public_id,'reason'=>'Changed my mind','resolution'=>'refund_original','items'=>[['orderItemId'=>$item->id,'quantity'=>1]]])->assertUnprocessable();
    }

    /** Verifies approved received return restocks original inventory and cod waits for manual cash confirmation. */
    public function test_approved_received_return_restocks_original_inventory_and_cod_waits_for_manual_cash_confirmation(): void
    {
        [$user,$order,$item,$inventory]=$this->order('cod',1,100_000,0); $admin=User::factory()->create(['role'=>UserRole::Admin]);
        Sanctum::actingAs($user); $ret=$this->postJson('/api/v1/returns',['orderId'=>$order->public_id,'reason'=>'Wrong item received','resolution'=>'refund_original'])->assertOk()->json('data');
        Sanctum::actingAs($admin); $this->postJson("/api/v1/admin/returns/{$ret['id']}/review",['approve'=>true])->assertOk();
        $received=$this->postJson("/api/v1/admin/returns/{$ret['id']}/receive")->assertOk()->assertJsonPath('data.refund.status','manual_payment_required')->json('data');
        $this->assertSame(10,$inventory->fresh()->on_hand);
        $this->assertDatabaseHas('inventory_movements',['type'=>'return','reference_type'=>'return_request_item']);
        $this->assertSame(0,(int)$order->fresh()->refunded_minor);
        $this->postJson("/api/v1/admin/refunds/{$received['refund']['id']}/confirm-manual",['reference'=>'CASH-REF-001'])->assertOk()->assertJsonPath('data.status','completed');
        $this->assertSame(100_000,(int)$order->fresh()->refunded_minor);
    }

    /** Verifies refund as coins credits wallet once and retry is idempotent. */
    public function test_refund_as_coins_credits_wallet_once_and_retry_is_idempotent(): void
    {
        [$user,$order]=$this->order('cod',1,100_000,0); Wallet::create(['user_id'=>$user->id,'balance_coins'=>0,'reserved_coins'=>0]); $admin=User::factory()->create(['role'=>UserRole::Admin]);
        Sanctum::actingAs($user); $ret=$this->postJson('/api/v1/returns',['orderId'=>$order->public_id,'reason'=>'Not as described','resolution'=>'coins'])->assertOk()->json('data');
        Sanctum::actingAs($admin); $this->postJson("/api/v1/admin/returns/{$ret['id']}/review",['approve'=>true])->assertOk();
        $this->postJson("/api/v1/admin/returns/{$ret['id']}/receive")->assertOk()->assertJsonPath('data.refund.status','completed');
        $this->postJson("/api/v1/admin/returns/{$ret['id']}/receive")->assertUnprocessable();
        $this->assertDatabaseHas('wallets',['user_id'=>$user->id,'balance_coins'=>70000]);
        $this->assertDatabaseCount('refunds',1);
    }

    /** Verifies card refund uses provider refund ledger and cannot exceed capture. */
    public function test_card_refund_uses_provider_refund_ledger_and_cannot_exceed_capture(): void
    {
        [$user,$order]=$this->order('card',1,100_000,0); $this->capturedIntent($user,$order,100_000); $admin=User::factory()->create(['role'=>UserRole::Admin]);
        Sanctum::actingAs($user); $ret=$this->postJson('/api/v1/returns',['orderId'=>$order->public_id,'reason'=>'Item damaged','resolution'=>'refund_original'])->assertOk()->json('data');
        Sanctum::actingAs($admin); $this->postJson("/api/v1/admin/returns/{$ret['id']}/review",['approve'=>true])->assertOk();
        $this->postJson("/api/v1/admin/returns/{$ret['id']}/receive")->assertOk()->assertJsonPath('data.refund.status','completed');
        $this->assertDatabaseHas('payment_transactions',['order_id'=>$order->id,'type'=>'refund','status'=>'succeeded','amount_minor'=>100_000]);
    }

    /** Verifies partial refund reverses seller payable and affiliate commission proportionally. */
    public function test_partial_refund_reverses_seller_payable_and_affiliate_commission_proportionally(): void
    {
        [$user,$order,$item]=$this->order('cod',2,100_000,1000); $beneficiary=User::factory()->create(); Wallet::create(['user_id'=>$beneficiary->id,'balance_coins'=>7000,'reserved_coins'=>0]);
        $commission=AffiliateCommission::create(['public_id'=>(string)Str::ulid(),'order_id'=>$order->id,'buyer_id'=>$user->id,'beneficiary_id'=>$beneficiary->id,'level_no'=>1,'rate_bps'=>1000,'currency'=>'PKR','eligible_spend_minor'=>100_000,'reward_coins'=>7000,'status'=>AffiliateCommissionStatus::Credited,'available_at'=>now(),'credited_at'=>now()]);
        $admin=User::factory()->create(['role'=>UserRole::Admin]); Sanctum::actingAs($user);
        $ret=$this->postJson('/api/v1/returns',['orderId'=>$order->public_id,'reason'=>'Item damaged','resolution'=>'coins','items'=>[['orderItemId'=>$item->id,'quantity'=>1]]])->assertOk()->json('data');
        Sanctum::actingAs($admin); $this->postJson("/api/v1/admin/returns/{$ret['id']}/review",['approve'=>true])->assertOk(); $this->postJson("/api/v1/admin/returns/{$ret['id']}/receive")->assertOk();
        $this->assertDatabaseHas('affiliate_commission_refunds',['affiliate_commission_id'=>$commission->id,'refunded_eligible_minor'=>50_000,'reversed_coins'=>3500]);
        $this->assertDatabaseHas('wallets',['user_id'=>$beneficiary->id,'balance_coins'=>3500]);
        $vendorOrder=$order->vendorOrders()->first(); $this->assertSame(5_000,(int)$vendorOrder->fresh()->platform_commission_reversed_minor);
        $this->assertSame(45_000,(int)$vendorOrder->fresh()->seller_payable_reversed_minor);
    }

    /** Verifies dispute requires admin outcome before return progresses. */
    public function test_dispute_requires_admin_outcome_before_return_progresses(): void
    {
        [$user,$order]=$this->order('cod',1,100_000,0); $admin=User::factory()->create(['role'=>UserRole::Admin]); Sanctum::actingAs($user);
        $ret=$this->postJson('/api/v1/returns',['orderId'=>$order->public_id,'reason'=>'Delivery issue','resolution'=>'dispute'])->assertOk()->assertJsonPath('data.status','disputed')->json('data');
        Sanctum::actingAs($admin); $this->postJson("/api/v1/admin/disputes/{$ret['dispute']['id']}/resolve",['outcome'=>'replacement','note'=>'Evidence supports buyer'])->assertOk()->assertJsonPath('data.outcome','replacement');
        $this->assertDatabaseHas('return_requests',['public_id'=>$ret['id'],'status'=>'approved','resolution'=>'replacement']);
    }

    /** Verifies customer can cancel submitted return before admin review. */
    public function test_customer_can_cancel_submitted_return_before_admin_review(): void
    {
        [$user,$order,$item]=$this->order('cod',1,100_000,0); Sanctum::actingAs($user);
        $ret=$this->postJson('/api/v1/returns',['orderId'=>$order->public_id,'reason'=>'Changed mind','resolution'=>'refund_original','items'=>[['orderItemId'=>$item->id,'quantity'=>1]]])->assertOk()->json('data');
        $this->postJson("/api/v1/returns/{$ret['id']}/cancel",[])->assertOk()->assertJsonPath('data.status','cancelled');
        $this->assertDatabaseHas('return_requests',['public_id'=>$ret['id'],'status'=>'cancelled']);
    }

    /** Verifies admin can partially approve return quantity. */
    public function test_admin_can_partially_approve_return_quantity(): void
    {
        [$user,$order,$item]=$this->order('cod',3,150_000,0); $admin=User::factory()->create(['role'=>UserRole::Admin]); Sanctum::actingAs($user);
        $ret=$this->postJson('/api/v1/returns',['orderId'=>$order->public_id,'reason'=>'Two damaged','resolution'=>'refund_original','items'=>[['orderItemId'=>$item->id,'quantity'=>3]]])->assertOk()->json('data');
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/returns/{$ret['id']}/review",['approve'=>true,'items'=>[['returnRequestItemId'=>$ret['items'][0]['id'],'approvedQuantity'=>2,'restock'=>true]]])->assertOk()->assertJsonPath('data.items.0.approvedQuantity',2)->assertJsonPath('data.approvedMinor',100_000);
    }

    /** Verifies warehouse inspection refunds only accepted quantity and does not restock damaged item. */
    public function test_warehouse_inspection_refunds_only_accepted_quantity_and_does_not_restock_damaged_item(): void
    {
        [$user,$order,$item,$inventory]=$this->order('cod',2,100_000,0); $admin=User::factory()->create(['role'=>UserRole::Admin]); Sanctum::actingAs($user);
        $ret=$this->postJson('/api/v1/returns',['orderId'=>$order->public_id,'reason'=>'Damaged parcel','resolution'=>'refund_original','items'=>[['orderItemId'=>$item->id,'quantity'=>2]]])->assertOk()->json('data');
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/returns/{$ret['id']}/review",['approve'=>true])->assertOk();
        $received=$this->postJson("/api/v1/admin/returns/{$ret['id']}/receive",['items'=>[['returnRequestItemId'=>$ret['items'][0]['id'],'receivedQuantity'=>2,'acceptedQuantity'=>1,'restock'=>false,'condition'=>'damaged','note'=>'One unit failed inspection']]])->assertOk()->assertJsonPath('data.approvedMinor',50_000)->json('data');
        $this->assertSame(9,(int)$inventory->fresh()->on_hand);
        $this->assertSame(1,(int)$item->fresh()->returned_quantity);
        $this->postJson("/api/v1/admin/refunds/{$received['refund']['id']}/confirm-manual",['reference'=>'CASH-INSPECT-001'])->assertOk();
        $this->assertSame(50_000,(int)$order->fresh()->refunded_minor);
        $this->assertSame(1,(int)$item->fresh()->refunded_quantity);
    }

    /** Verifies manual cod refund requires payment reference. */
    public function test_manual_cod_refund_requires_payment_reference(): void
    {
        [$user,$order]=$this->order('cod',1,100_000,0); $admin=User::factory()->create(['role'=>UserRole::Admin]); Sanctum::actingAs($user);
        $ret=$this->postJson('/api/v1/returns',['orderId'=>$order->public_id,'reason'=>'Wrong item','resolution'=>'refund_original'])->assertOk()->json('data');
        Sanctum::actingAs($admin);$this->postJson("/api/v1/admin/returns/{$ret['id']}/review",['approve'=>true])->assertOk();
        $received=$this->postJson("/api/v1/admin/returns/{$ret['id']}/receive",[])->assertOk()->json('data');
        $this->postJson("/api/v1/admin/refunds/{$received['refund']['id']}/confirm-manual",[])->assertUnprocessable();
        $this->postJson("/api/v1/admin/refunds/{$received['refund']['id']}/confirm-manual",['reference'=>'BANK-REF-88'])->assertOk()->assertJsonPath('data.status','completed');
        $this->assertDatabaseHas('refunds',['public_id'=>$received['refund']['id'],'manual_reference'=>'BANK-REF-88']);
    }

    /** Verifies refund timeline records attempt manual confirmation and completion. */
    public function test_refund_timeline_records_attempt_manual_confirmation_and_completion(): void
    {
        [$user,$order]=$this->order('cod',1,100_000,0); $admin=User::factory()->create(['role'=>UserRole::Admin]); Sanctum::actingAs($user);
        $ret=$this->postJson('/api/v1/returns',['orderId'=>$order->public_id,'reason'=>'Return timeline','resolution'=>'refund_original'])->assertOk()->json('data');
        Sanctum::actingAs($admin);$this->postJson("/api/v1/admin/returns/{$ret['id']}/review",['approve'=>true])->assertOk();
        $received=$this->postJson("/api/v1/admin/returns/{$ret['id']}/receive",[])->assertOk()->json('data');
        $this->postJson("/api/v1/admin/refunds/{$received['refund']['id']}/confirm-manual",['reference'=>'CASH-TIMELINE-1'])->assertOk();
        $refund=Refund::query()->where('public_id',$received['refund']['id'])->firstOrFail();
        $this->assertDatabaseHas('refund_events',['refund_id'=>$refund->id,'event'=>'attempt_started']);
        $this->assertDatabaseHas('refund_events',['refund_id'=>$refund->id,'event'=>'manual_payment_confirmed']);
        $this->assertDatabaseHas('refund_events',['refund_id'=>$refund->id,'event'=>'completed']);
    }

    /** Handles order for the returns refunds api test workflow. */
    private function order(string $method,int $qty,int $lineTotal,int $commissionBps): array
    {
        $user=User::factory()->create(); $vendor=Vendor::create(['name'=>'Seller','slug'=>'seller-'.Str::lower((string)Str::ulid()),'status'=>'active','commission_bps'=>$commissionBps]);
        $product=Product::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'sku'=>'P-'.Str::ulid(),'slug'=>'p-'.Str::lower((string)Str::ulid()),'name'=>'Returnable product','status'=>ProductStatus::Published,'currency'=>'PKR','base_price_minor'=>intdiv($lineTotal,$qty)]);
        $variant=ProductVariant::create(['product_id'=>$product->id,'sku'=>'V-'.Str::ulid(),'name'=>'Default','price_minor'=>intdiv($lineTotal,$qty),'is_default'=>true,'is_active'=>true]);
        $warehouse=Warehouse::create(['code'=>'WH-'.Str::ulid(),'name'=>'Main']); $inventory=Inventory::create(['warehouse_id'=>$warehouse->id,'product_variant_id'=>$variant->id,'on_hand'=>9,'reserved'=>0,'safety_stock'=>0]);
        $cart=Cart::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'status'=>CartStatus::Converted,'currency'=>'PKR']);
        $session=CheckoutSession::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'cart_id'=>$cart->id,'idempotency_key'=>'ret-'.Str::uuid(),'status'=>CheckoutStatus::Converted,'currency'=>'PKR','address_snapshot'=>['recipient_name'=>$user->name,'phone'=>'0300','line1'=>'Test','city'=>'Lahore','country_code'=>'PK'],'shipping_method'=>'standard','payment_method'=>$method,'subtotal_minor'=>$lineTotal,'shipping_minor'=>0,'discount_minor'=>0,'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>$lineTotal,'expires_at'=>now()->addMinutes(15),'converted_at'=>now()]);
        $order=Order::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'checkout_session_id'=>$session->id,'status'=>OrderStatus::Delivered,'payment_status'=>PaymentStatus::Paid,'payment_method'=>$method,'currency'=>'PKR','subtotal_minor'=>$lineTotal,'shipping_minor'=>0,'discount_minor'=>0,'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>$lineTotal,'placed_at'=>now()->subDay(),'delivered_at'=>now()]);
        $vo=$order->vendorOrders()->create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'status'=>OrderStatus::Delivered,'currency'=>'PKR','subtotal_minor'=>$lineTotal,'shipping_minor'=>0,'discount_minor'=>0,'total_minor'=>$lineTotal,'commission_bps'=>$commissionBps,'platform_commission_minor'=>intdiv($lineTotal*$commissionBps,10000),'seller_payable_minor'=>$lineTotal-intdiv($lineTotal*$commissionBps,10000)]);
        $item=$order->items()->create(['vendor_order_id'=>$vo->id,'product_id'=>$product->id,'product_variant_id'=>$variant->id,'product_name'=>$product->name,'variant_name'=>'Default','sku'=>$variant->sku,'quantity'=>$qty,'currency'=>'PKR','unit_price_minor'=>intdiv($lineTotal,$qty),'line_total_minor'=>$lineTotal]);
        InventoryMovement::create(['inventory_id'=>$inventory->id,'type'=>InventoryMovementType::Sale,'on_hand_delta'=>-$qty,'reserved_delta'=>0,'reference_type'=>'order_item','reference_id'=>(string)$item->id]);
        return [$user,$order,$item,$inventory];
    }

    /** Handles captured intent for the returns refunds api test workflow. */
    private function capturedIntent(User $user, Order $order, int $amount): void
    {
        $intent=PaymentIntent::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'checkout_session_id'=>$order->checkout_session_id,'order_id'=>$order->id,'idempotency_key'=>'pi-'.Str::uuid(),'purpose'=>'checkout','provider'=>'sandbox','payment_method'=>'card','status'=>PaymentIntentStatus::Paid,'currency'=>'PKR','amount_minor'=>$amount,'provider_payment_id'=>'sbx_'.Str::ulid(),'paid_at'=>now()]);
        PaymentTransaction::create(['public_id'=>(string)Str::ulid(),'payment_intent_id'=>$intent->id,'order_id'=>$order->id,'provider'=>'sandbox','type'=>PaymentTransactionType::Capture,'status'=>PaymentTransactionStatus::Succeeded,'currency'=>'PKR','amount_minor'=>$amount,'provider_transaction_id'=>'cap_'.Str::ulid(),'idempotency_key'=>'cap-'.Str::uuid(),'occurred_at'=>now()]);
    }
}
