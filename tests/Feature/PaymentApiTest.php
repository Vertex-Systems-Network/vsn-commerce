<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Address;
use App\Models\Inventory;
use App\Models\PaymentIntent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the PaymentApiTest class and its project responsibilities. */
class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    /** Updates up. */
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'vsn.payments.methods.card.enabled' => true,
            'vsn.payments.methods.card.provider' => 'sandbox',
            'vsn.payments.providers.sandbox.webhook_secret' => 'test-webhook-secret',
            'vsn.payments.providers.sandbox.simulator_enabled' => true,
        ]);
    }

    /** Verifies card checkout creates server amount payment intent idempotently. */
    public function test_card_checkout_creates_server_amount_payment_intent_idempotently(): void
    {
        [$user, $address, $product] = $this->scenario(priceMinor: 25_000_00, stock: 3);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 2])->assertOk();
        $session = $this->createCardCheckout($address, 'payment-checkout-001');

        $payload = ['idempotencyKey' => 'payment-intent-idem-001'];
        $first = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments", $payload)
            ->assertOk()
            ->assertJsonPath('data.provider', 'sandbox')
            ->assertJsonPath('data.status', 'requires_action')
            ->assertJsonPath('data.amountMinor', 50_250_00)
            ->json('data');

        $this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments", $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $first['id']);

        $this->assertDatabaseCount('payment_intents', 1);
    }

    /** Verifies failed initialization can be retried on same intent without creating duplicate record. */
    public function test_failed_initialization_can_be_retried_on_same_intent_without_creating_duplicate_record(): void
    {
        [$user,$address,$product]=$this->scenario(priceMinor:9_000_00,stock:2);Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items',['productId'=>$product->id,'quantity'=>1])->assertOk();
        $session=$this->createCardCheckout($address,'payment-init-retry-checkout');
        $data=$this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments",['idempotencyKey'=>'payment-init-retry'])->assertOk()->json('data');
        $intent=PaymentIntent::where('public_id',$data['id'])->firstOrFail();
        $intent->forceFill(['status'=>'failed','provider_payment_id'=>null,'client_action'=>null,'failed_at'=>now()])->save();
        $this->postJson("/api/v1/payments/{$intent->public_id}/retry-initialization")->assertOk()->assertJsonPath('data.id',$intent->public_id)->assertJsonPath('data.status','requires_action');
        $this->assertDatabaseCount('payment_intents',1);
        $this->assertGreaterThanOrEqual(2,$intent->fresh()->initialization_attempts);
    }

    /** Verifies customer can refresh provider state without bypassing signed webhook. */
    public function test_customer_can_refresh_provider_state_without_bypassing_signed_webhook(): void
    {
        [$user,$address,$product]=$this->scenario(priceMinor:7_000_00,stock:2);Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items',['productId'=>$product->id,'quantity'=>1])->assertOk();
        $session=$this->createCardCheckout($address,'payment-provider-refresh');
        $data=$this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments",['idempotencyKey'=>'payment-provider-refresh-intent'])->assertOk()->json('data');
        $this->postJson("/api/v1/payments/{$data['id']}/refresh-provider")->assertOk()->assertJsonPath('data.status','requires_action')->assertJsonPath('data.providerStatus','requires_action');
        $this->assertDatabaseCount('orders',0);
    }

    /** Verifies direct order endpoint cannot bypass online payment. */
    public function test_direct_order_endpoint_cannot_bypass_online_payment(): void
    {
        [$user, $address, $product] = $this->scenario(priceMinor: 10_000_00, stock: 2);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertOk();
        $session = $this->createCardCheckout($address, 'payment-bypass-001');

        $this->postJson("/api/v1/checkout/sessions/{$session['id']}/order", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment.0', 'Online-payment orders can only be finalized after a verified payment webhook.');

        $this->assertDatabaseCount('orders', 0);
    }

    /** Verifies signed paid webhook creates paid order and payment ledger entry. */
    public function test_signed_paid_webhook_creates_paid_order_and_payment_ledger_entry(): void
    {
        [$user, $address, $product, $inventory] = $this->scenario(priceMinor: 30_000_00, stock: 4);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 2])->assertOk();
        $session = $this->createCardCheckout($address, 'payment-paid-001');
        $intentData = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments", [
            'idempotencyKey' => 'payment-paid-intent-001',
        ])->assertOk()->json('data');
        $intent = PaymentIntent::query()->where('public_id', $intentData['id'])->firstOrFail();

        $this->signedWebhook($intent, 'evt-paid-001', 'payment.paid')
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        $intent->refresh();
        $this->assertSame('paid', $intent->status->value);
        $this->assertNotNull($intent->order_id);
        $this->assertDatabaseHas('orders', ['id' => $intent->order_id, 'payment_status' => 'paid']);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_intent_id' => $intent->id,
            'type' => 'capture',
            'status' => 'succeeded',
            'amount_minor' => $intent->amount_minor,
        ]);
        $this->assertSame(2, $inventory->fresh()->on_hand);
        $this->assertSame(0, $inventory->fresh()->reserved);
    }

    /** Verifies duplicate webhook is idempotent. */
    public function test_duplicate_webhook_is_idempotent(): void
    {
        [$user, $address, $product] = $this->scenario(priceMinor: 15_000_00, stock: 2);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertOk();
        $session = $this->createCardCheckout($address, 'payment-duplicate-001');
        $intentData = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments", [
            'idempotencyKey' => 'payment-duplicate-intent-001',
        ])->assertOk()->json('data');
        $intent = PaymentIntent::query()->where('public_id', $intentData['id'])->firstOrFail();

        $payload = $this->webhookPayload($intent, 'evt-duplicate-001', 'payment.paid');
        $this->signedWebhookRaw($payload)->assertOk()->assertJsonPath('data.status', 'processed');
        $this->signedWebhookRaw($payload)->assertOk()->assertJsonPath('data.status', 'processed');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertDatabaseHas('payment_webhook_events', ['provider_event_id'=>'evt-duplicate-001','duplicate_count'=>1]);
    }

    /** Verifies same payment event id with changed payload is rejected as replay mismatch. */
    public function test_same_payment_event_id_with_changed_payload_is_rejected_as_replay_mismatch(): void
    {
        [$user,$address,$product]=$this->scenario(priceMinor:13_000_00,stock:2);Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items',['productId'=>$product->id,'quantity'=>1])->assertOk();
        $session=$this->createCardCheckout($address,'payment-replay-checkout');
        $data=$this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments",['idempotencyKey'=>'payment-replay-intent'])->assertOk()->json('data');
        $intent=PaymentIntent::where('public_id',$data['id'])->firstOrFail();
        $first=$this->webhookPayload($intent,'evt-replay-payment','payment.failed');$this->signedWebhookRaw($first)->assertOk();
        $changed=$first;$changed['type']='payment.paid';
        $this->signedWebhookRaw($changed)->assertStatus(422)->assertJsonPath('message','Payment webhook replay payload mismatch.');
        $this->assertSame('failed',$intent->fresh()->status->value);
    }

    /** Verifies invalid webhook signature is rejected without state change. */
    public function test_invalid_webhook_signature_is_rejected_without_state_change(): void
    {
        [$user, $address, $product] = $this->scenario(priceMinor: 12_000_00, stock: 2);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertOk();
        $session = $this->createCardCheckout($address, 'payment-signature-001');
        $intentData = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments", [
            'idempotencyKey' => 'payment-signature-intent-001',
        ])->assertOk()->json('data');
        $intent = PaymentIntent::query()->where('public_id', $intentData['id'])->firstOrFail();
        $raw = json_encode($this->webhookPayload($intent, 'evt-invalid-signature-001', 'payment.paid'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/payments/webhooks/sandbox', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_VSN_SIGNATURE' => 'invalid',
        ], $raw)->assertUnauthorized();

        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    /** Verifies paid amount mismatch is held for manual review not fulfilled. */
    public function test_paid_amount_mismatch_is_held_for_manual_review_not_fulfilled(): void
    {
        [$user, $address, $product, $inventory] = $this->scenario(priceMinor: 18_000_00, stock: 2);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertOk();
        $session = $this->createCardCheckout($address, 'payment-mismatch-001');
        $intentData = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments", [
            'idempotencyKey' => 'payment-mismatch-intent-001',
        ])->assertOk()->json('data');
        $intent = PaymentIntent::query()->where('public_id', $intentData['id'])->firstOrFail();
        $payload = $this->webhookPayload($intent, 'evt-mismatch-001', 'payment.paid');
        $payload['amount_minor'] = $intent->amount_minor - 100;

        $this->signedWebhookRaw($payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_review');

        $this->assertSame('needs_review', $intent->fresh()->status->value);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('payment_transactions', ['amount_minor' => $intent->amount_minor - 100, 'type' => 'capture']);
        $this->assertSame(1, $inventory->fresh()->reserved);
    }

    /** Verifies failed payment does not consume inventory and can be retried. */
    public function test_failed_payment_does_not_consume_inventory_and_can_be_retried(): void
    {
        [$user, $address, $product, $inventory] = $this->scenario(priceMinor: 8_000_00, stock: 2);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertOk();
        $session = $this->createCardCheckout($address, 'payment-failed-001');
        $firstData = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments", [
            'idempotencyKey' => 'payment-failed-intent-001',
        ])->assertOk()->json('data');
        $first = PaymentIntent::query()->where('public_id', $firstData['id'])->firstOrFail();

        $this->signedWebhook($first, 'evt-failed-001', 'payment.failed')
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        $this->assertSame('failed', $first->fresh()->status->value);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, $inventory->fresh()->reserved);

        $second = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments", [
            'idempotencyKey' => 'payment-failed-intent-002',
        ])->assertOk()->json('data.id');
        $this->assertNotSame($firstData['id'], $second);
        $this->assertDatabaseCount('payment_intents', 2);
    }

    /** Verifies paid webhook after checkout release is recorded as needs review. */
    public function test_paid_webhook_after_checkout_release_is_recorded_as_needs_review(): void
    {
        [$user, $address, $product, $inventory] = $this->scenario(priceMinor: 11_000_00, stock: 2);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertOk();
        $session = $this->createCardCheckout($address, 'payment-late-001');
        $intentData = $this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments", [
            'idempotencyKey' => 'payment-late-intent-001',
        ])->assertOk()->json('data');
        $intent = PaymentIntent::query()->where('public_id', $intentData['id'])->firstOrFail();

        $this->deleteJson("/api/v1/checkout/sessions/{$session['id']}")->assertOk();
        $this->assertSame(0, $inventory->fresh()->reserved);

        $this->signedWebhook($intent, 'evt-late-001', 'payment.paid')
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_review');

        $this->assertSame('needs_review', $intent->fresh()->status->value);
        $this->assertDatabaseHas('payment_transactions', ['payment_intent_id' => $intent->id, 'type' => 'capture']);
        $this->assertDatabaseCount('orders', 0);
    }

    /** Handles create card checkout for the payment api test workflow. */
    private function createCardCheckout(Address $address, string $idempotencyKey): array
    {
        return $this->postJson('/api/v1/checkout/sessions', [
            'addressId' => $address->id,
            'shippingMethod' => 'standard',
            'paymentMethod' => 'card',
            'idempotencyKey' => $idempotencyKey,
        ])->assertOk()->json('data');
    }

    /** Handles signed webhook for the payment api test workflow. */
    private function signedWebhook(PaymentIntent $intent, string $eventId, string $eventType)
    {
        return $this->signedWebhookRaw($this->webhookPayload($intent, $eventId, $eventType));
    }

    /** Handles signed webhook raw for the payment api test workflow. */
    private function signedWebhookRaw(array $payload)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $raw, 'test-webhook-secret');

        return $this->call('POST', '/api/v1/payments/webhooks/sandbox', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_VSN_SIGNATURE' => $signature,
        ], $raw);
    }

    /** Handles webhook payload for the payment api test workflow. */
    private function webhookPayload(PaymentIntent $intent, string $eventId, string $eventType): array
    {
        return [
            'event_id' => $eventId,
            'type' => $eventType,
            'payment_intent_id' => $intent->public_id,
            'provider_payment_id' => $intent->provider_payment_id,
            'provider_transaction_id' => 'txn-'.$eventId,
            'amount_minor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    /** Handles scenario for the payment api test workflow. */
    private function scenario(int $priceMinor, int $stock): array
    {
        $user = User::factory()->create();
        $address = Address::create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => $user->name,
            'phone' => '03001234567',
            'line1' => '1 Payment Street',
            'city' => 'Lahore',
            'state' => 'Punjab',
            'postal_code' => '54000',
            'country_code' => 'PK',
            'is_default' => true,
        ]);
        $vendor = Vendor::create([
            'name' => 'Payment Seller',
            'slug' => 'payment-seller-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'commission_bps' => 1000,
        ]);
        $product = Product::create([
            'public_id' => (string) Str::ulid(),
            'vendor_id' => $vendor->id,
            'sku' => 'PAY-'.Str::upper(Str::random(8)),
            'slug' => 'pay-product-'.Str::lower(Str::random(8)),
            'name' => 'Payment Product',
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
        $warehouse = Warehouse::create(['code' => 'PAY-'.Str::upper(Str::random(6)), 'name' => 'Payment Warehouse']);
        $inventory = Inventory::create([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'on_hand' => $stock,
            'reserved' => 0,
            'safety_stock' => 0,
        ]);

        return [$user, $address, $product, $inventory, $variant];
    }
}
