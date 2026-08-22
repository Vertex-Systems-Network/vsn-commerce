<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOrder;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the CheckoutOrderLifecycleTest class and its project responsibilities. */
class CheckoutOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies cart mutation invalidates reserved checkout and releases stock. */
    public function test_cart_mutation_invalidates_reserved_checkout_and_releases_stock(): void
    {
        [$buyer, $address, $seller, $vendor, $product, $variant, $inventory] = $this->market();
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['productId'=>$product->id,'quantity'=>1])->assertOk();
        $session = $this->checkout($address, 'cod', 'ai-cart-reserve-001');
        $this->assertSame(1, $inventory->fresh()->reserved);

        $item = CartItem::query()->where('product_variant_id', $variant->id)->firstOrFail();
        $this->patchJson("/api/v1/cart/items/{$item->id}", ['quantity'=>2])->assertOk();

        $this->assertDatabaseHas('checkout_sessions', ['public_id'=>$session['id'],'status'=>'cancelled']);
        $this->assertSame(0, $inventory->fresh()->reserved);
        $this->assertDatabaseHas('inventory_reservations', ['status'=>'released']);
    }

    /** Verifies current checkout can resume with latest payment intent. */
    public function test_current_checkout_can_resume_with_latest_payment_intent(): void
    {
        [$buyer, $address, , , $product] = $this->market();
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['productId'=>$product->id,'quantity'=>1])->assertOk();
        $session = $this->checkout($address, 'card', 'ai-resume-checkout-001');
        $intent = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments", ['idempotencyKey'=>'ai-resume-payment-001'])
            ->assertOk()->json('data');

        $this->getJson('/api/v1/checkout/current')
            ->assertOk()
            ->assertJsonPath('data.id', $session['id'])
            ->assertJsonPath('data.activePaymentIntent.id', $intent['id']);
    }

    /** Verifies seller pack reconciles master order to packed. */
    public function test_seller_pack_reconciles_master_order_to_packed(): void
    {
        [$buyer, $address, $seller, , $product] = $this->market();
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['productId'=>$product->id,'quantity'=>1])->assertOk();
        $session = $this->checkout($address, 'cod', 'ai-pack-checkout-001');
        $order = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/order", [])->assertOk()->json('data');
        $vendorOrderId = $order['sellerOrders'][0]['id'];

        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/vendor/orders/{$vendorOrderId}/pack", [])->assertOk()->assertJsonPath('data.status','packed');
        $this->assertDatabaseHas('orders', ['public_id'=>$order['id'],'status'=>'packed']);
    }

    /** Verifies online payment order cannot be packed before verified payment. */
    public function test_online_payment_order_cannot_be_packed_before_verified_payment(): void
    {
        [$buyer, $address, $seller, $vendor, $product] = $this->market();
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['productId'=>$product->id,'quantity'=>1])->assertOk();
        $session = $this->checkout($address, 'card', 'ai-unpaid-pack-001');
        $sessionModel = \App\Models\CheckoutSession::query()->where('public_id',$session['id'])->firstOrFail();
        $order = Order::create([
            'public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'checkout_session_id'=>$sessionModel->id,
            'status'=>OrderStatus::Confirmed,'payment_status'=>PaymentStatus::Pending,'payment_method'=>'card','currency'=>'PKR',
            'subtotal_minor'=>100000,'shipping_minor'=>25000,'discount_minor'=>0,'coin_redemption_minor'=>0,'total_minor'=>125000,'placed_at'=>now(),
        ]);
        $vendorOrder = VendorOrder::create([
            'public_id'=>(string)Str::ulid(),'order_id'=>$order->id,'vendor_id'=>$vendor->id,'status'=>OrderStatus::Confirmed,'currency'=>'PKR',
            'subtotal_minor'=>100000,'shipping_minor'=>25000,'discount_minor'=>0,'total_minor'=>125000,'commission_bps'=>1000,'platform_commission_minor'=>10000,'seller_payable_minor'=>115000,
        ]);

        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/vendor/orders/{$vendorOrder->public_id}/pack", [])->assertUnprocessable();
        $this->assertSame('confirmed', $vendorOrder->fresh()->status->value);
    }

    /** Verifies admin cannot jump order into shipping owned state. */
    public function test_admin_cannot_jump_order_into_shipping_owned_state(): void
    {
        [$buyer, $address, , , $product] = $this->market();
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['productId'=>$product->id,'quantity'=>1])->assertOk();
        $session = $this->checkout($address, 'cod', 'ai-admin-state-001');
        $order = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/order", [])->assertOk()->json('data');

        $admin = User::factory()->create(['role'=>UserRole::Admin]);
        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/admin/orders/{$order['id']}/status", ['status'=>'delivered'])->assertUnprocessable();
        $this->assertDatabaseHas('orders', ['public_id'=>$order['id'],'status'=>'confirmed']);
    }

    /** Handles checkout for the checkout order lifecycle test workflow. */
    private function checkout(Address $address, string $payment, string $key): array
    {
        return $this->postJson('/api/v1/checkout/sessions', [
            'addressId'=>$address->id,'shippingMethod'=>'standard','paymentMethod'=>$payment,'idempotencyKey'=>$key,
        ])->assertOk()->json('data');
    }

    /** Handles market for the checkout order lifecycle test workflow. */
    private function market(): array
    {
        $buyer = User::factory()->create(['role'=>UserRole::Customer]);
        $seller = User::factory()->create(['role'=>UserRole::Seller]);
        $address = Address::create(['user_id'=>$buyer->id,'label'=>'Home','recipient_name'=>$buyer->name,'phone'=>'03001234567','line1'=>'1 Main Road','city'=>'Lahore','state'=>'Punjab','postal_code'=>'54000','country_code'=>'PK','is_default'=>true]);
        $vendor = Vendor::create(['owner_user_id'=>$seller->id,'name'=>'AI Seller','slug'=>'ai-seller-'.Str::lower(Str::random(5)),'status'=>'active','commission_bps'=>1000]);
        $product = Product::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'sku'=>'AI-'.Str::upper(Str::random(6)),'slug'=>'ai-'.Str::lower(Str::random(8)),'name'=>'AI Product','status'=>ProductStatus::Published,'currency'=>'PKR','base_price_minor'=>100000]);
        $variant = ProductVariant::create(['product_id'=>$product->id,'name'=>'Default','sku'=>'AIV-'.Str::upper(Str::random(6)),'price_minor'=>100000,'is_active'=>true,'option_values'=>[]]);
        $warehouse = Warehouse::firstOrCreate(['code'=>'MAIN'],['name'=>'Main','is_active'=>true]);
        $inventory = Inventory::create(['warehouse_id'=>$warehouse->id,'product_variant_id'=>$variant->id,'on_hand'=>10,'reserved'=>0,'safety_stock'=>0]);
        return [$buyer,$address,$seller,$vendor,$product,$variant,$inventory];
    }
}
