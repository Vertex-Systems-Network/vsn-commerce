<?php

namespace Tests\Feature;

use App\Domain\Finance\Actions\ReconcileVendorSettlements;
use App\Domain\Shipping\Actions\CheckShippingSlas;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the ShippingApiTest class and its project responsibilities. */
class ShippingApiTest extends TestCase
{
    use RefreshDatabase;

    /** Updates up. */
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('vsn.shipping.providers.sandbox.webhook_secret', 'shipping-test-secret');
        config()->set('vsn.shipping.providers.sandbox.simulator_enabled', true);
    }

    /** Verifies seller can pack create label and customer can track shipment. */
    public function test_seller_can_pack_create_label_and_customer_can_track_shipment(): void
    {
        [$customer,$owners,$order] = $this->order();
        $vendorOrder = $order->vendorOrders()->firstOrFail();
        Sanctum::actingAs($owners[0]);
        $this->postJson("/api/v1/vendor/orders/{$vendorOrder->public_id}/pack")->assertOk()->assertJsonPath('data.status', 'packed');
        $shipment = $this->postJson("/api/v1/vendor/orders/{$vendorOrder->public_id}/shipments", ['serviceCode' => 'standard', 'idempotencyKey' => 'shipment-001'])->assertOk()->assertJsonPath('data.status', 'label_created')->json('data');
        $this->assertNotEmpty($shipment['trackingNumber']);
        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/shipments')->assertOk()->assertJsonPath('data.0.id', $shipment['id']);
    }

    /** Verifies shipment creation is idempotent and does not duplicate parcels. */
    public function test_shipment_creation_is_idempotent_and_does_not_duplicate_parcels(): void
    {
        [, $owners,$order] = $this->order();
        $vo = $order->vendorOrders()->firstOrFail();
        Sanctum::actingAs($owners[0]);
        $a = $this->postJson("/api/v1/vendor/orders/{$vo->public_id}/shipments", ['serviceCode' => 'standard', 'idempotencyKey' => 'shipment-idem'])->assertOk()->json('data.id');
        $b = $this->postJson("/api/v1/vendor/orders/{$vo->public_id}/shipments", ['serviceCode' => 'standard', 'idempotencyKey' => 'shipment-idem'])->assertOk()->json('data.id');
        $this->assertSame($a, $b);
        $this->assertDatabaseCount('shipments', 1);
    }

    /** Verifies pending shipment provider creation can be retried without new shipment row. */
    public function test_pending_shipment_provider_creation_can_be_retried_without_new_shipment_row(): void
    {
        [, $owners,$order] = $this->order();
        $shipment = $this->createShipment($owners[0], $order);
        $shipment->forceFill(['provider_shipment_id' => null, 'tracking_number' => null, 'status' => 'pending'])->save();
        Sanctum::actingAs($owners[0]);
        $this->postJson("/api/v1/vendor/shipments/{$shipment->public_id}/retry-create")->assertOk()->assertJsonPath('data.id', $shipment->public_id)->assertJsonPath('data.status', 'label_created');
        $this->assertDatabaseCount('shipments', 1);
        $this->assertGreaterThanOrEqual(2, $shipment->fresh()->creation_attempts);
    }

    /** Verifies seller can cancel label before pickup and create replacement later. */
    public function test_seller_can_cancel_label_before_pickup_and_create_replacement_later(): void
    {
        [, $owners,$order] = $this->order();
        $shipment = $this->createShipment($owners[0], $order);
        Sanctum::actingAs($owners[0]);
        $this->postJson("/api/v1/vendor/shipments/{$shipment->public_id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $vo = $order->vendorOrders()->firstOrFail();
        $this->postJson("/api/v1/vendor/orders/{$vo->public_id}/shipments", ['serviceCode' => 'standard', 'idempotencyKey' => 'replacement-'.$vo->public_id])->assertOk()->assertJsonPath('data.status', 'label_created');
        $this->assertDatabaseCount('shipments', 2);
    }

    /** Verifies seller cannot upgrade customer shipping service without new checkout. */
    public function test_seller_cannot_upgrade_customer_shipping_service_without_new_checkout(): void
    {
        [, $owners,$order] = $this->order();
        $vo = $order->vendorOrders()->firstOrFail();
        Sanctum::actingAs($owners[0]);
        $this->postJson("/api/v1/vendor/orders/{$vo->public_id}/shipments", ['serviceCode' => 'express', 'idempotencyKey' => 'shipment-wrong-service'])->assertStatus(422);
    }

    /** Verifies unpaid online order cannot be shipped. */
    public function test_unpaid_online_order_cannot_be_shipped(): void
    {
        [, $owners,$order] = $this->order();
        $order->update(['payment_method' => 'card', 'payment_status' => PaymentStatus::Pending]);
        $vo = $order->vendorOrders()->firstOrFail();
        Sanctum::actingAs($owners[0]);
        $this->postJson("/api/v1/vendor/orders/{$vo->public_id}/shipments", ['serviceCode' => 'standard', 'idempotencyKey' => 'shipment-unpaid-card'])->assertStatus(422);
        $this->assertDatabaseCount('shipments', 0);
    }

    /** Verifies signed webhook updates tracking and duplicate replay is idempotent. */
    public function test_signed_webhook_updates_tracking_and_duplicate_replay_is_idempotent(): void
    {
        [$customer,$owners,$order] = $this->order();
        $shipment = $this->createShipment($owners[0], $order);
        $payload = ['id' => 'carrier-evt-001', 'shipment_id' => $shipment->provider_shipment_id, 'tracking_number' => $shipment->tracking_number, 'status' => 'picked_up', 'occurred_at' => now()->toIso8601String(), 'message' => 'Courier collected parcel', 'location' => 'Lahore Hub'];
        $this->shippingWebhook($payload)->assertOk()->assertJsonPath('data.status', 'picked_up');
        $this->shippingWebhook($payload)->assertOk()->assertJsonPath('data.status', 'picked_up');
        $this->assertSame(2, ShipmentEvent::query()->where('shipment_id', $shipment->id)->count()); // label + pickup
        $this->assertDatabaseHas('shipping_webhook_events', ['provider_event_id' => 'carrier-evt-001', 'duplicate_count' => 1]);
        $this->assertSame('shipped', $order->vendorOrders()->first()->fresh()->status->value);
        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/shipments/{$shipment->public_id}")->assertOk()->assertJsonPath('data.events.1.location', 'Lahore Hub');
    }

    /** Verifies same provider event id with changed payload is rejected as replay mismatch. */
    public function test_same_provider_event_id_with_changed_payload_is_rejected_as_replay_mismatch(): void
    {
        [, $owners,$order] = $this->order();
        $shipment = $this->createShipment($owners[0], $order);
        $first = ['id' => 'carrier-replay-001', 'shipment_id' => $shipment->provider_shipment_id, 'status' => 'picked_up', 'occurred_at' => now()->toIso8601String(), 'message' => 'Collected'];
        $this->shippingWebhook($first)->assertOk();
        $changed = $first;
        $changed['status'] = 'delivered';
        $changed['message'] = 'Changed replay payload';
        $this->shippingWebhook($changed)->assertStatus(422)->assertJsonPath('message', 'Shipping webhook replay payload mismatch.');
        $this->assertSame('picked_up', $shipment->fresh()->status->value);
        $this->assertDatabaseHas('shipping_webhook_events', ['provider' => 'sandbox', 'provider_event_id' => 'carrier-replay-001', 'status' => 'rejected']);
    }

    /** Verifies invalid webhook signature is rejected without state change. */
    public function test_invalid_webhook_signature_is_rejected_without_state_change(): void
    {
        [, $owners,$order] = $this->order();
        $shipment = $this->createShipment($owners[0], $order);
        $payload = json_encode(['id' => 'bad-signature', 'shipment_id' => $shipment->provider_shipment_id, 'status' => 'delivered', 'occurred_at' => now()->toIso8601String()]);
        $this->call('POST', '/api/v1/shipping/webhooks/sandbox', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_VSN_SIGNATURE' => 'sha256=bad'], $payload)->assertStatus(422);
        $this->assertNull($shipment->fresh()->delivered_at);
    }

    /** Verifies out of order carrier event is recorded but does not regress projection. */
    public function test_out_of_order_carrier_event_is_recorded_but_does_not_regress_projection(): void
    {
        [, $owners,$order] = $this->order();
        $shipment = $this->createShipment($owners[0], $order);
        $base = now();
        $this->shippingWebhook(['id' => 'evt-ofd', 'shipment_id' => $shipment->provider_shipment_id, 'status' => 'out_for_delivery', 'occurred_at' => $base->toIso8601String()])->assertOk();
        $this->shippingWebhook(['id' => 'evt-late-pickup', 'shipment_id' => $shipment->provider_shipment_id, 'status' => 'picked_up', 'occurred_at' => $base->addMinute()->toIso8601String()])->assertOk();
        $this->assertSame('out_for_delivery', $shipment->fresh()->status->value);
        $this->assertDatabaseHas('shipment_events', ['provider_event_id' => 'evt-late-pickup', 'status' => 'picked_up']);
    }

    /** Verifies delivery event marks vendor delivery and releases that sellers finance hold. */
    public function test_delivery_event_marks_vendor_delivery_and_releases_that_sellers_finance_hold(): void
    {
        [, $owners,$order] = $this->order();
        $order->update(['payment_status' => PaymentStatus::Paid]);
        $shipment = $this->createShipment($owners[0], $order);
        $this->shippingWebhook(['id' => 'evt-delivered', 'shipment_id' => $shipment->provider_shipment_id, 'status' => 'delivered', 'occurred_at' => now()->toIso8601String()])->assertOk();
        $vo = $order->vendorOrders()->first()->fresh();
        $this->assertNotNull($vo->delivered_at);
        $this->assertSame('delivered', $order->fresh()->status->value);
        app(ReconcileVendorSettlements::class)->execute($vo->vendor_id);
        $this->assertDatabaseHas('vendor_settlements', ['vendor_order_id' => $vo->id, 'status' => 'hold_return_window']);
    }

    /** Verifies multi vendor delivery does not mark master delivered until all shipments arrive. */
    public function test_multi_vendor_delivery_does_not_mark_master_delivered_until_all_shipments_arrive(): void
    {
        [, $owners,$order] = $this->order(2);
        $order->update(['payment_status' => PaymentStatus::Paid]);
        $shipA = $this->createShipment($owners[0], $order, 0);
        $this->shippingWebhook(['id' => 'multi-a-delivered', 'shipment_id' => $shipA->provider_shipment_id, 'status' => 'delivered', 'occurred_at' => now()->toIso8601String()])->assertOk();
        $this->assertSame('shipped', $order->fresh()->status->value);
        $this->assertNull($order->fresh()->delivered_at);
        $shipB = $this->createShipment($owners[1], $order, 1);
        $this->shippingWebhook(['id' => 'multi-b-delivered', 'shipment_id' => $shipB->provider_shipment_id, 'status' => 'delivered', 'occurred_at' => now()->addMinute()->toIso8601String()])->assertOk();
        $this->assertSame('delivered', $order->fresh()->status->value);
        $this->assertNotNull($order->fresh()->delivered_at);
    }

    /** Verifies sla checker marks overdue dispatch and events are immutable. */
    public function test_sla_checker_marks_overdue_dispatch_and_events_are_immutable(): void
    {
        [, $owners,$order] = $this->order();
        $shipment = $this->createShipment($owners[0], $order);
        $shipment->update(['dispatch_due_at' => now()->subMinute()]);
        $result = app(CheckShippingSlas::class)->execute();
        $this->assertSame(1, $result['dispatchBreaches']);
        $this->assertNotNull($shipment->fresh()->dispatch_breached_at);
        $event = ShipmentEvent::query()->where('shipment_id', $shipment->id)->where('code', 'sla.dispatch_breached')->firstOrFail();
        $this->expectException(\LogicException::class);
        $event->update(['message' => 'tampered']);
    }

    /** Verifies admin quality uses real shipping metrics and other seller cannot control shipment. */
    public function test_admin_quality_uses_real_shipping_metrics_and_other_seller_cannot_control_shipment(): void
    {
        [, $owners,$order] = $this->order(2);
        $shipment = $this->createShipment($owners[0], $order, 0);
        Sanctum::actingAs($owners[1]);
        $this->postJson("/api/v1/vendor/shipments/{$shipment->public_id}/ready")->assertNotFound();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/shipping/quality')->assertOk()->assertJsonCount(2, 'data');
    }

    /** Handles create shipment for the shipping api test workflow. */
    private function createShipment(User $owner, Order $order, int $vendorIndex = 0): Shipment
    {
        $vo = $order->vendorOrders()->orderBy('id')->get()[$vendorIndex];
        Sanctum::actingAs($owner);
        $data = $this->postJson("/api/v1/vendor/orders/{$vo->public_id}/shipments", ['serviceCode' => 'standard', 'idempotencyKey' => 'shipment-'.$vo->public_id])->assertOk()->json('data');

        return Shipment::where('public_id', $data['id'])->firstOrFail();
    }

    /** Handles shipping webhook for the shipping api test workflow. */
    private function shippingWebhook(array $payload)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'shipping-test-secret');

        return $this->call('POST', '/api/v1/shipping/webhooks/sandbox', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_VSN_SIGNATURE' => $signature], $raw);
    }

    /** Handles order for the shipping api test workflow. */
    private function order(int $vendorCount = 1): array
    {
        $customer = User::factory()->create();
        $address = Address::create(['user_id' => $customer->id, 'label' => 'Home', 'recipient_name' => $customer->name, 'phone' => '03001234567', 'line1' => '1 Test Street', 'city' => 'Lahore', 'state' => 'Punjab', 'postal_code' => '54000', 'country_code' => 'PK', 'is_default' => true]);
        $owners = [];
        $products = [];
        for ($i = 0; $i < $vendorCount; $i++) {
            $owner = User::factory()->create(['role' => UserRole::Seller]);
            $owners[] = $owner;
            $vendor = Vendor::create(['owner_user_id' => $owner->id, 'name' => 'Seller '.($i + 1), 'slug' => 'seller-'.$i.'-'.Str::lower(Str::random(5)), 'status' => 'active', 'commission_bps' => 1000]);
            $product = Product::create(['public_id' => (string) Str::ulid(), 'vendor_id' => $vendor->id, 'sku' => 'SHIP-'.Str::upper(Str::random(6)), 'slug' => 'ship-'.Str::lower(Str::random(8)), 'name' => 'Shipping Product '.($i + 1), 'status' => ProductStatus::Published, 'currency' => 'PKR', 'base_price_minor' => 100_000]);
            $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => $product->sku.'-D', 'name' => 'Default', 'price_minor' => 100_000, 'is_default' => true, 'is_active' => true]);
            $warehouse = Warehouse::create(['code' => 'SHP-'.Str::upper(Str::random(6)), 'name' => 'Shipping Test Warehouse']);
            Inventory::create(['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 10, 'reserved' => 0, 'safety_stock' => 0]);
            $products[] = $product;
        }
        Sanctum::actingAs($customer);
        foreach ($products as $product) {
            $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertOk();
        }
        $session = $this->postJson('/api/v1/checkout/sessions', ['addressId' => $address->id, 'shippingMethod' => 'standard', 'paymentMethod' => 'cod', 'idempotencyKey' => 'shipping-checkout-'.Str::uuid()])->assertOk()->json('data');
        $placed = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/order", [])->assertOk()->json('data');

        return [$customer, $owners, Order::where('public_id', $placed['id'])->with('vendorOrders')->firstOrFail()];
    }
}
