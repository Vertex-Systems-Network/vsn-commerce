<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the CheckoutApiTest class and its project responsibilities. */
class CheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies checkout session uses server totals and reserves stock idempotently. */
    public function test_checkout_session_uses_server_totals_and_reserves_stock_idempotently(): void
    {
        [$user, $address] = $this->customer();
        [$product, $variant, $inventory] = $this->productWithStock('seller-a', 100_000_00, 5);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 2])->assertOk();

        $payload = [
            'addressId' => $address->id,
            'shippingMethod' => 'standard',
            'paymentMethod' => 'cod',
            'idempotencyKey' => 'checkout-idempotent-001',
        ];

        $first = $this->postJson('/api/v1/checkout/sessions', $payload)
            ->assertOk()
            ->assertJsonPath('data.totals.subtotalMinor', 200_000_00)
            ->assertJsonPath('data.totals.shippingMinor', 25_000)
            ->assertJsonPath('data.totals.totalMinor', 200_250_00)
            ->json('data');

        $this->postJson('/api/v1/checkout/sessions', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $first['id']);

        $this->assertSame(2, $inventory->fresh()->reserved);
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    /** Verifies place order converts reservation and creates seller order. */
    public function test_place_order_converts_reservation_and_creates_seller_order(): void
    {
        [$user, $address] = $this->customer();
        [$product, $variant, $inventory] = $this->productWithStock('seller-a', 50_000_00, 4, 1200);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 2])->assertOk();
        $session = $this->postJson('/api/v1/checkout/sessions', [
            'addressId' => $address->id,
            'shippingMethod' => 'standard',
            'paymentMethod' => 'cod',
            'idempotencyKey' => 'checkout-place-001',
        ])->assertOk()->json('data');

        $order = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/order", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.paymentStatus', 'pending')
            ->assertJsonCount(1, 'data.sellerOrders')
            ->json('data');

        $this->assertNotEmpty($order['id']);
        $this->assertSame(2, $inventory->fresh()->on_hand);
        $this->assertSame(0, $inventory->fresh()->reserved);
        $this->assertDatabaseHas('inventory_reservations', ['status' => 'converted']);
        $this->assertDatabaseHas('carts', ['user_id' => $user->id, 'status' => 'converted']);
        $this->assertDatabaseHas('vendor_orders', ['commission_bps' => 1200]);
        $this->assertDatabaseCount('order_items', 1);
    }

    /** Verifies multi vendor checkout splits order and shipping. */
    public function test_multi_vendor_checkout_splits_order_and_shipping(): void
    {
        [$user, $address] = $this->customer();
        [$productA] = $this->productWithStock('seller-a', 10_000_00, 2, 1000);
        [$productB] = $this->productWithStock('seller-b', 20_000_00, 2, 800);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', ['productId' => $productA->id, 'quantity' => 1])->assertOk();
        $this->postJson('/api/v1/cart/items', ['productId' => $productB->id, 'quantity' => 1])->assertOk();

        $session = $this->postJson('/api/v1/checkout/sessions', [
            'addressId' => $address->id,
            'shippingMethod' => 'standard',
            'paymentMethod' => 'cod',
            'idempotencyKey' => 'checkout-multi-001',
        ])->assertOk()
            ->assertJsonPath('data.shippingQuote.vendorCount', 2)
            ->assertJsonPath('data.totals.shippingMinor', 50_000)
            ->json('data');

        $this->postJson("/api/v1/checkout/sessions/{$session['id']}/order", [])
            ->assertOk()
            ->assertJsonCount(2, 'data.sellerOrders');

        $this->assertDatabaseCount('vendor_orders', 2);
        $this->assertSame([25_000, 25_000], \App\Models\VendorOrder::query()->orderBy('id')->pluck('shipping_minor')->map(/** Inline callback for this operation. */ fn ($value) => (int) $value)->all());
    }

    /** Verifies cancelling checkout releases reserved stock. */
    public function test_cancelling_checkout_releases_reserved_stock(): void
    {
        [$user, $address] = $this->customer();
        [$product, $variant, $inventory] = $this->productWithStock('seller-a', 12_000_00, 3);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 2])->assertOk();
        $session = $this->postJson('/api/v1/checkout/sessions', [
            'addressId' => $address->id,
            'shippingMethod' => 'standard',
            'paymentMethod' => 'cod',
            'idempotencyKey' => 'checkout-cancel-001',
        ])->assertOk()->json('data');

        $this->assertSame(2, $inventory->fresh()->reserved);

        $this->deleteJson("/api/v1/checkout/sessions/{$session['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, $inventory->fresh()->reserved);
        $this->assertDatabaseHas('inventory_reservations', ['status' => 'released']);
    }

    /** Verifies unknown coupon fails closed but coin redemption creates and releases wallet hold. */
    public function test_unknown_coupon_fails_closed_but_coin_redemption_creates_and_releases_wallet_hold(): void
    {
        [$user, $address] = $this->customer();
        [$product] = $this->productWithStock('seller-a', 8_000_00, 2);
        \App\Models\Wallet::create(['user_id' => $user->id, 'balance_coins' => 7_000, 'reserved_coins' => 0]);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertOk();

        $base = [
            'addressId' => $address->id,
            'shippingMethod' => 'standard',
            'paymentMethod' => 'cod',
        ];

        $this->postJson('/api/v1/checkout/sessions', $base + [
            'idempotencyKey' => 'checkout-coupon-001',
            'couponCode' => 'REVIEW10',
        ])->assertUnprocessable()->assertJsonPath('errors.couponCode.0', 'This coupon or promotion code is invalid or inactive.');

        $session = $this->postJson('/api/v1/checkout/sessions', $base + [
            'idempotencyKey' => 'checkout-coins-001',
            'coinRedemptionCoins' => 700,
        ])->assertOk()
            ->assertJsonPath('data.totals.coinRedemptionCoins', 700)
            ->assertJsonPath('data.totals.coinRedemptionMinor', 1_000)
            ->json('data');

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance_coins' => 7_000, 'reserved_coins' => 700]);
        $this->assertDatabaseHas('wallet_holds', ['user_id' => $user->id, 'coins' => 700, 'status' => 'active']);

        $this->deleteJson("/api/v1/checkout/sessions/{$session['id']}")->assertOk();
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance_coins' => 7_000, 'reserved_coins' => 0]);
        $this->assertDatabaseHas('wallet_holds', ['user_id' => $user->id, 'status' => 'released']);
    }

    /** Verifies coin hold is captured into wallet ledger when order is placed. */
    public function test_coin_hold_is_captured_into_wallet_ledger_when_order_is_placed(): void
    {
        [$user, $address] = $this->customer();
        [$product] = $this->productWithStock('seller-a', 8_000_00, 2);
        \App\Models\Wallet::create(['user_id' => $user->id, 'balance_coins' => 7_000, 'reserved_coins' => 0]);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertOk();

        $session = $this->postJson('/api/v1/checkout/sessions', [
            'addressId' => $address->id,
            'shippingMethod' => 'standard',
            'paymentMethod' => 'cod',
            'coinRedemptionCoins' => 700,
            'idempotencyKey' => 'checkout-coins-capture-001',
        ])->assertOk()->json('data');

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance_coins' => 7_000, 'reserved_coins' => 700]);

        $this->postJson("/api/v1/checkout/sessions/{$session['id']}/order", [])
            ->assertOk()
            ->assertJsonPath('data.totals.coinRedemptionCoins', 700);

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance_coins' => 6_300, 'reserved_coins' => 0]);
        $this->assertDatabaseHas('wallet_holds', ['user_id' => $user->id, 'status' => 'captured']);
        $this->assertDatabaseHas('wallet_transactions', ['type' => 'checkout_redemption']);
    }

    /** Verifies full coin checkout requires full funding and places paid order without provider. */
    public function test_full_coin_checkout_requires_full_funding_and_places_paid_order_without_provider(): void
    {
        [$user, $address] = $this->customer();
        [$product] = $this->productWithStock('seller-a', 100, 2);
        $requiredCoins = 17_570; // Rs 1 product + Rs 250 standard shipping = Rs 251 at 70 coins/Rs.
        \App\Models\Wallet::create(['user_id' => $user->id, 'balance_coins' => $requiredCoins, 'reserved_coins' => 0]);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertOk();

        $session = $this->postJson('/api/v1/checkout/sessions', [
            'addressId' => $address->id,
            'shippingMethod' => 'standard',
            'paymentMethod' => 'coins',
            'coinRedemptionCoins' => $requiredCoins,
            'idempotencyKey' => 'checkout-full-coins-001',
        ])->assertOk()
            ->assertJsonPath('data.totals.totalMinor', 0)
            ->assertJsonPath('data.totals.coinRedemptionCoins', $requiredCoins)
            ->json('data');

        $this->postJson("/api/v1/checkout/sessions/{$session['id']}/order", [])
            ->assertOk()
            ->assertJsonPath('data.paymentStatus', 'paid')
            ->assertJsonPath('data.totals.totalMinor', 0);

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance_coins' => 0, 'reserved_coins' => 0]);
        $this->assertDatabaseCount('payment_intents', 0);
    }

    /** Verifies order endpoint is idempotent for a checkout session. */
    public function test_order_endpoint_is_idempotent_for_a_checkout_session(): void
    {
        [$user, $address] = $this->customer();
        [$product, $variant, $inventory] = $this->productWithStock('seller-a', 9_000_00, 2);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertOk();
        $session = $this->postJson('/api/v1/checkout/sessions', [
            'addressId' => $address->id,
            'shippingMethod' => 'standard',
            'paymentMethod' => 'cod',
            'idempotencyKey' => 'checkout-order-idem-001',
        ])->assertOk()->json('data');

        $first = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/order", [])->assertOk()->json('data.id');
        $second = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/order", [])->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, $inventory->fresh()->on_hand);
    }

    /** Handles customer for the checkout api test workflow. */
    private function customer(): array
    {
        $user = User::factory()->create();
        $address = Address::create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => $user->name,
            'phone' => '03001234567',
            'line1' => '1 Test Street',
            'city' => 'Lahore',
            'state' => 'Punjab',
            'postal_code' => '54000',
            'country_code' => 'PK',
            'is_default' => true,
        ]);

        return [$user, $address];
    }

    /** Handles product with stock for the checkout api test workflow. */
    private function productWithStock(string $vendorSlug, int $priceMinor, int $stock, int $commissionBps = 1000): array
    {
        $vendor = Vendor::create([
            'name' => Str::headline($vendorSlug),
            'slug' => $vendorSlug,
            'status' => 'active',
            'commission_bps' => $commissionBps,
        ]);
        $product = Product::create([
            'public_id' => (string) Str::ulid(),
            'vendor_id' => $vendor->id,
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'slug' => 'product-'.Str::lower(Str::random(8)),
            'name' => 'Checkout Product',
            'status' => ProductStatus::Published,
            'currency' => 'PKR',
            'base_price_minor' => $priceMinor,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $product->sku.'-A',
            'name' => 'Default',
            'price_minor' => $priceMinor,
            'is_default' => true,
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'code' => 'WH-'.Str::upper(Str::random(6)),
            'name' => 'Test Warehouse',
        ]);
        $inventory = Inventory::create([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'on_hand' => $stock,
            'reserved' => 0,
            'safety_stock' => 0,
        ]);

        return [$product, $variant, $inventory, $vendor];
    }
}
