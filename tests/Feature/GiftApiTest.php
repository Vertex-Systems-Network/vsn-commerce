<?php

namespace Tests\Feature;

use App\Domain\Gifts\Actions\DispatchGiftNotifications;
use App\Domain\Gifts\Actions\ReconcileGiftStatuses;
use App\Enums\ProductStatus;
use App\Enums\GiftRewardStatus;
use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\GiftSenderProfile;
use App\Models\GiftSenderReward;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the GiftApiTest class and its project responsibilities. */
class GiftApiTest extends TestCase
{
    use RefreshDatabase;

    /** Updates up. */
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'vsn.payments.methods.card.enabled' => true,
            'vsn.payments.methods.card.provider' => 'sandbox',
            'vsn.payments.providers.sandbox.webhook_secret' => 'gift-test-secret',
            'vsn.payments.providers.sandbox.simulator_enabled' => true,
            'vsn.gifts.cod_enabled' => false,
        ]);
    }

    /** Verifies gift checkout keeps recipient shipping address private from sender. */
    public function test_gift_checkout_keeps_recipient_shipping_address_private_from_sender(): void
    {
        [$sender, $recipient] = $this->people();
        [$product, $variant] = $this->productWithStock(100_000, 3);
        Wallet::create(['user_id'=>$sender->id,'balance_coins'=>100_000,'reserved_coins'=>0]);
        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/v1/gifts/checkouts', $this->giftPayload($recipient, $variant, 'gift-private-001'))
            ->assertCreated()
            ->assertJsonPath('data.checkout.address.private', true)
            ->assertJsonPath('data.checkout.address.recipient', 'Gift recipient')
            ->assertJsonPath('data.gift.recipient.name', $recipient->name);

        $json = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('99 Recipient Lane', $json);
        $this->assertStringNotContainsString('03009998888', $json);
        $this->assertSame($product->id, $response->json('data.gift.product.id'));
    }

    /** Verifies sender cannot send product gift to self. */
    public function test_sender_cannot_send_product_gift_to_self(): void
    {
        [$sender] = $this->people();
        [, $variant] = $this->productWithStock(100_000, 2);
        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/gifts/checkouts', $this->giftPayload($sender, $variant, 'gift-self-001'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.recipient.0', 'You cannot send a product gift to yourself.');
    }

    /** Verifies recipient without saved address cannot receive product gift. */
    public function test_recipient_without_saved_address_cannot_receive_product_gift(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        [, $variant] = $this->productWithStock(100_000, 2);
        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/gifts/checkouts', $this->giftPayload($recipient, $variant, 'gift-no-address-001'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.recipient.0', 'Recipient cannot receive product gifts until a delivery address is saved.');
    }

    /** Verifies gift cod is blocked by default. */
    public function test_gift_cod_is_blocked_by_default(): void
    {
        [$sender, $recipient] = $this->people();
        [, $variant] = $this->productWithStock(100_000, 2);
        Sanctum::actingAs($sender);
        $payload = $this->giftPayload($recipient, $variant, 'gift-cod-001');
        $payload['paymentMethod'] = 'cod';

        $this->postJson('/api/v1/gifts/checkouts', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.paymentMethod.0', 'Cash on delivery is disabled for recipient-paid gift deliveries. Use card or VSN Coins.');
    }

    /** Verifies full coin product gift places paid order and records progress once. */
    public function test_full_coin_product_gift_places_paid_order_and_records_progress_once(): void
    {
        [$sender, $recipient] = $this->people();
        [, $variant, $inventory] = $this->productWithStock(100_000, 3);
        Wallet::create(['user_id'=>$sender->id,'balance_coins'=>100_000,'reserved_coins'=>0]);
        Sanctum::actingAs($sender);

        $gift = $this->postJson('/api/v1/gifts/checkouts', $this->giftPayload($recipient, $variant, 'gift-coins-001'))
            ->assertCreated()->json('data');
        $this->assertDatabaseHas('wallets', ['user_id'=>$sender->id,'balance_coins'=>100_000,'reserved_coins'=>87_500]);

        $firstOrder = $this->postJson("/api/v1/checkout/sessions/{$gift['checkout']['id']}/order", [])
            ->assertOk()->assertJsonPath('data.paymentStatus', 'paid')->json('data.id');
        $secondOrder = $this->postJson("/api/v1/checkout/sessions/{$gift['checkout']['id']}/order", [])
            ->assertOk()->json('data.id');

        $this->assertSame($firstOrder, $secondOrder);
        $this->assertDatabaseHas('wallets', ['user_id'=>$sender->id,'balance_coins'=>12_500,'reserved_coins'=>0]);
        $this->assertSame(2, $inventory->fresh()->on_hand);
        $this->assertSame(0, $inventory->fresh()->reserved);
        $this->assertDatabaseCount('gift_sender_events', 1);
        $this->assertDatabaseHas('gift_sender_profiles', ['user_id'=>$sender->id,'lifetime_gift_coins'=>70_000]);
        $this->assertDatabaseHas('gifts', ['sender_user_id'=>$sender->id,'recipient_user_id'=>$recipient->id,'status'=>'processing']);
    }

    /** Verifies cancelling unpaid coin gift releases wallet hold and inventory. */
    public function test_cancelling_unpaid_coin_gift_releases_wallet_hold_and_inventory(): void
    {
        [$sender, $recipient] = $this->people();
        [, $variant, $inventory] = $this->productWithStock(100_000, 2);
        Wallet::create(['user_id'=>$sender->id,'balance_coins'=>100_000,'reserved_coins'=>0]);
        Sanctum::actingAs($sender);

        $data = $this->postJson('/api/v1/gifts/checkouts', $this->giftPayload($recipient, $variant, 'gift-cancel-001'))
            ->assertCreated()->json('data');
        $this->assertSame(1, $inventory->fresh()->reserved);
        $this->assertDatabaseHas('wallets', ['user_id'=>$sender->id,'reserved_coins'=>87_500]);

        $this->postJson("/api/v1/gifts/{$data['gift']['id']}/cancel", [])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, $inventory->fresh()->reserved);
        $this->assertDatabaseHas('wallets', ['user_id'=>$sender->id,'balance_coins'=>100_000,'reserved_coins'=>0]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('gift_sender_events', 0);
    }

    /** Verifies scheduled recipient message stays hidden until notification release. */
    public function test_scheduled_recipient_message_stays_hidden_until_notification_release(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00'));
        [$sender, $recipient] = $this->people();
        [, $variant] = $this->productWithStock(100_000, 2);
        Wallet::create(['user_id'=>$sender->id,'balance_coins'=>100_000,'reserved_coins'=>0]);
        Sanctum::actingAs($sender);
        $payload = $this->giftPayload($recipient, $variant, 'gift-scheduled-001');
        $payload['scheduledFor'] = '2026-08-10T10:00:00+00:00';
        $payload['message'] = 'Open this on your special day.';
        $data = $this->postJson('/api/v1/gifts/checkouts', $payload)->assertCreated()->json('data');
        $this->postJson("/api/v1/checkout/sessions/{$data['checkout']['id']}/order", [])->assertOk();

        Sanctum::actingAs($recipient);
        $this->getJson('/api/v1/gifts')->assertOk()->assertJsonPath('data.received.0.message', null);

        Carbon::setTestNow(Carbon::parse('2026-08-10 10:01:00'));
        app(DispatchGiftNotifications::class)->execute();
        $this->getJson('/api/v1/gifts')->assertOk()->assertJsonPath('data.received.0.message', 'Open this on your special day.');
        $this->assertDatabaseHas('gift_notifications', ['gift_id'=>$this->giftId($data['gift']['id']),'status'=>'delivered']);
        Carbon::setTestNow();
    }

    /** Verifies anonymous product gift hides sender identity from recipient. */
    public function test_anonymous_product_gift_hides_sender_identity_from_recipient(): void
    {
        [$sender, $recipient] = $this->people();
        [, $variant] = $this->productWithStock(100_000, 2);
        Wallet::create(['user_id'=>$sender->id,'balance_coins'=>100_000,'reserved_coins'=>0]);
        Sanctum::actingAs($sender);
        $payload = $this->giftPayload($recipient, $variant, 'gift-anon-001');
        $payload['anonymous'] = true;
        $data = $this->postJson('/api/v1/gifts/checkouts', $payload)->assertCreated()->json('data');
        $this->postJson("/api/v1/checkout/sessions/{$data['checkout']['id']}/order", [])->assertOk();

        Sanctum::actingAs($recipient);
        $response = $this->getJson('/api/v1/gifts')->assertOk()->assertJsonPath('data.received.0.sender.name', 'Anonymous');
        $this->assertStringNotContainsString($sender->name, json_encode($response->json('data.received.0'), JSON_THROW_ON_ERROR));
    }

    /** Verifies coin gift updates progress and gold threshold bonus is idempotent. */
    public function test_coin_gift_updates_progress_and_gold_threshold_bonus_is_idempotent(): void
    {
        [$sender, $recipient] = $this->people();
        Wallet::create(['user_id'=>$sender->id,'balance_coins'=>50_000,'reserved_coins'=>0]);
        Wallet::create(['user_id'=>$recipient->id,'balance_coins'=>0,'reserved_coins'=>0]);
        Sanctum::actingAs($sender);
        $payload = ['recipient'=>$recipient->email,'coins'=>35_000,'idempotencyKey'=>'coin-gift-progress-001'];

        $this->postJson('/api/v1/wallet/transfers', $payload)->assertOk();
        $this->postJson('/api/v1/wallet/transfers', $payload)->assertOk();

        $this->assertDatabaseHas('gift_sender_profiles', ['user_id'=>$sender->id,'lifetime_gift_coins'=>35_000,'current_level'=>'gold']);
        $this->assertDatabaseCount('gift_sender_events', 1);
        $this->assertDatabaseHas('gift_sender_rewards', ['user_id'=>$sender->id,'reward_code'=>'free_gift_wrap']);
        $this->assertDatabaseHas('gift_sender_rewards', ['user_id'=>$sender->id,'reward_code'=>'gift_bonus_500']);
        $this->assertDatabaseHas('wallets', ['user_id'=>$sender->id,'balance_coins'=>15_500,'reserved_coins'=>0]);
        $this->assertDatabaseHas('wallet_transactions', ['type'=>'gift_level_reward']);
    }

    /** Verifies gift tracking reconciles to fulfilled when underlying order is delivered. */
    public function test_gift_tracking_reconciles_to_fulfilled_when_underlying_order_is_delivered(): void
    {
        [$sender, $recipient] = $this->people();
        [, $variant] = $this->productWithStock(100_000, 2);
        Wallet::create(['user_id'=>$sender->id,'balance_coins'=>100_000,'reserved_coins'=>0]);
        Sanctum::actingAs($sender);
        $data = $this->postJson('/api/v1/gifts/checkouts', $this->giftPayload($recipient, $variant, 'gift-track-001'))->assertCreated()->json('data');
        $orderId = $this->postJson("/api/v1/checkout/sessions/{$data['checkout']['id']}/order", [])->assertOk()->json('data.id');
        Order::query()->where('public_id',$orderId)->firstOrFail()->update(['status'=>OrderStatus::Delivered]);

        app(ReconcileGiftStatuses::class)->execute();

        Sanctum::actingAs($recipient);
        $this->getJson('/api/v1/gifts')->assertOk()
            ->assertJsonPath('data.received.0.status', 'fulfilled')
            ->assertJsonPath('data.received.0.orderStatus', 'delivered');
    }

    /** Verifies free gift wrap reward is reserved then consumed only after paid order. */
    public function test_free_gift_wrap_reward_is_reserved_then_consumed_only_after_paid_order(): void
    {
        [$sender, $recipient] = $this->people();
        [, $variant] = $this->productWithStock(100_000, 2);
        Wallet::create(['user_id'=>$sender->id,'balance_coins'=>120_000,'reserved_coins'=>0]);
        $reward = GiftSenderReward::create([
            'public_id'=>(string)Str::ulid(),'user_id'=>$sender->id,'reward_code'=>'free_gift_wrap','level'=>'silver',
            'status'=>GiftRewardStatus::Available,'metadata'=>['label'=>'Free gift wrap'],'awarded_at'=>now(),
        ]);
        Sanctum::actingAs($sender);
        $payload = $this->giftPayload($recipient, $variant, 'gift-free-wrap-001');
        $payload['giftWrap'] = true;

        $data = $this->postJson('/api/v1/gifts/checkouts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.gift.totals.giftWrapMinor', 0)
            ->assertJsonPath('data.gift.totals.giftWrapDiscountMinor', 29_900)
            ->assertJsonPath('data.gift.giftWrapRewardApplied', true)
            ->json('data');

        $this->assertSame(GiftRewardStatus::Reserved, $reward->fresh()->status);
        $this->postJson("/api/v1/checkout/sessions/{$data['checkout']['id']}/order", [])->assertOk();
        $this->assertSame(GiftRewardStatus::Consumed, $reward->fresh()->status);
        $this->assertNotNull($reward->fresh()->consumed_at);
    }

    /** Verifies cancelled gift releases reserved free wrap reward for future use. */
    public function test_cancelled_gift_releases_reserved_free_wrap_reward_for_future_use(): void
    {
        [$sender, $recipient] = $this->people();
        [, $variant] = $this->productWithStock(100_000, 2);
        Wallet::create(['user_id'=>$sender->id,'balance_coins'=>120_000,'reserved_coins'=>0]);
        $reward = GiftSenderReward::create([
            'public_id'=>(string)Str::ulid(),'user_id'=>$sender->id,'reward_code'=>'free_gift_wrap','level'=>'silver',
            'status'=>GiftRewardStatus::Available,'metadata'=>['label'=>'Free gift wrap'],'awarded_at'=>now(),
        ]);
        Sanctum::actingAs($sender);
        $payload = $this->giftPayload($recipient, $variant, 'gift-free-wrap-cancel-001');
        $payload['giftWrap'] = true;
        $data = $this->postJson('/api/v1/gifts/checkouts', $payload)->assertCreated()->json('data');
        $this->assertSame(GiftRewardStatus::Reserved, $reward->fresh()->status);

        $this->postJson("/api/v1/gifts/{$data['gift']['id']}/cancel", [])->assertOk();
        $this->assertSame(GiftRewardStatus::Available, $reward->fresh()->status);
        $this->assertNull($reward->fresh()->consumed_at);
    }

    /** Handles people for the gift api test workflow. */
    private function people(): array
    {
        $sender = User::factory()->create(['name'=>'Gift Sender']);
        $recipient = User::factory()->create(['name'=>'Gift Recipient']);
        Address::create([
            'user_id'=>$sender->id,'label'=>'Home','recipient_name'=>$sender->name,'phone'=>'03001112222','line1'=>'11 Sender Street',
            'city'=>'Lahore','state'=>'Punjab','postal_code'=>'54000','country_code'=>'PK','is_default'=>true,
        ]);
        Address::create([
            'user_id'=>$recipient->id,'label'=>'Home','recipient_name'=>$recipient->name,'phone'=>'03009998888','line1'=>'99 Recipient Lane',
            'city'=>'Karachi','state'=>'Sindh','postal_code'=>'74000','country_code'=>'PK','is_default'=>true,
        ]);
        return [$sender,$recipient];
    }

    /** Handles product with stock for the gift api test workflow. */
    private function productWithStock(int $priceMinor, int $stock): array
    {
        $vendor = Vendor::create(['name'=>'Gift Seller','slug'=>'gift-seller-'.Str::lower(Str::random(6)),'status'=>'active','commission_bps'=>1000]);
        $product = Product::create([
            'public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'sku'=>'GIFT-'.Str::upper(Str::random(8)),
            'slug'=>'gift-product-'.Str::lower(Str::random(8)),'name'=>'Gift Product','status'=>ProductStatus::Published,
            'currency'=>'PKR','base_price_minor'=>$priceMinor,
        ]);
        $variant = ProductVariant::create([
            'product_id'=>$product->id,'sku'=>$product->sku.'-DEFAULT','name'=>'Default','price_minor'=>$priceMinor,
            'is_default'=>true,'is_active'=>true,'option_values'=>[],
        ]);
        $warehouse = Warehouse::create(['code'=>'WH-'.Str::upper(Str::random(6)),'name'=>'Gift Warehouse']);
        $inventory = Inventory::create(['warehouse_id'=>$warehouse->id,'product_variant_id'=>$variant->id,'on_hand'=>$stock,'reserved'=>0,'safety_stock'=>0]);
        return [$product,$variant,$inventory,$vendor];
    }

    /** Handles gift payload for the gift api test workflow. */
    private function giftPayload(User $recipient, ProductVariant $variant, string $key): array
    {
        return [
            'recipient'=>$recipient->email,
            'variantId'=>$variant->id,
            'message'=>'A gift for you',
            'giftWrap'=>false,
            'anonymous'=>false,
            'shippingMethod'=>'standard',
            'paymentMethod'=>'coins',
            'idempotencyKey'=>$key,
        ];
    }

    /** Handles gift id for the gift api test workflow. */
    private function giftId(string $publicId): int
    {
        return (int) \App\Models\Gift::query()->where('public_id',$publicId)->value('id');
    }
}
