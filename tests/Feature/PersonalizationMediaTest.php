<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Defines the PersonalizationMediaTest class and its project responsibilities. */
class PersonalizationMediaTest extends TestCase
{
    use RefreshDatabase;

    /** Handles product for the milestone qpersonalization media test workflow. */
    private function product(?User $owner = null): Product
    {
        $owner ??= User::factory()->create();
        $owner->update(['role' => UserRole::Seller]);
        $vendor = Vendor::create([
            'owner_user_id' => $owner->id,
            'name' => 'Store',
            'slug' => 'store-'.Str::lower(Str::random(5)),
            'status' => 'active',
            'commission_bps' => 1000,
        ]);

        return Product::create([
            'public_id' => (string) Str::ulid(),
            'vendor_id' => $vendor->id,
            'slug' => 'p-'.Str::lower(Str::random(5)),
            'name' => 'Product',
            'status' => ProductStatus::Published,
            'currency' => 'PKR',
            'base_price_minor' => 100000,
            'rating' => 4.5,
            'reviews_count' => 1,
            'sold_count' => 3,
        ]);
    }

    /** Verifies user can add and remove wishlist without duplicates. */
    public function test_user_can_add_and_remove_wishlist_without_duplicates(): void
    {
        $u = User::factory()->create();
        $p = $this->product();
        $this->actingAs($u)->postJson("/api/v1/wishlist/products/{$p->slug}")->assertCreated();
        $this->actingAs($u)->postJson("/api/v1/wishlist/products/{$p->slug}")->assertOk();
        $this->assertDatabaseCount('wishlist_items', 1);
        $id = $u->wishlistItems()->first()->public_id;
        $this->actingAs($u)->deleteJson("/api/v1/wishlist/{$id}")->assertOk();
        $this->assertDatabaseCount('wishlist_items', 0);
    }

    /** Verifies cross user cannot delete wishlist item. */
    public function test_cross_user_cannot_delete_wishlist_item(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $p = $this->product();
        $this->actingAs($a)->postJson("/api/v1/wishlist/products/{$p->slug}");
        $id = $a->wishlistItems()->first()->public_id;
        $this->actingAs($b)->deleteJson("/api/v1/wishlist/{$id}")->assertNotFound();
    }

    /** Verifies product view is deduplicated inside window. */
    public function test_product_view_is_deduplicated_inside_window(): void
    {
        $u = User::factory()->create();
        $p = $this->product();
        $this->actingAs($u)->withHeader('X-Device-Id', 'abc')->postJson("/api/v1/products/{$p->slug}/views", [])->assertOk();
        $this->actingAs($u)->withHeader('X-Device-Id', 'abc')->postJson("/api/v1/products/{$p->slug}/views", [])->assertOk();
        $this->assertDatabaseCount('product_views', 1);
    }

    /** Verifies anonymous view without device identifier is not recorded. */
    public function test_anonymous_view_without_device_identifier_is_not_recorded(): void
    {
        $p = $this->product();
        $this->postJson("/api/v1/products/{$p->slug}/views", [])->assertOk()->assertJsonPath('data.recorded', false);
        $this->assertDatabaseCount('product_views', 0);
    }

    /** Verifies recently viewed is account scoped. */
    public function test_recently_viewed_is_account_scoped(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $p = $this->product();
        $this->actingAs($a)->postJson("/api/v1/products/{$p->slug}/views");
        $this->actingAs($b)->getJson('/api/v1/recently-viewed')->assertOk()->assertJsonCount(0, 'data.items');
    }

    /** Verifies user can clear only their recent history. */
    public function test_user_can_clear_only_their_recent_history(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $p = $this->product();
        $this->actingAs($a)->postJson("/api/v1/products/{$p->slug}/views");
        $this->actingAs($b)->postJson("/api/v1/products/{$p->slug}/views");
        $this->actingAs($a)->deleteJson('/api/v1/recently-viewed')->assertOk();
        $this->assertDatabaseMissing('product_views', ['user_id' => $a->id]);
        $this->assertDatabaseHas('product_views', ['user_id' => $b->id]);
    }

    /** Verifies buy again uses real paid order items. */
    public function test_buy_again_uses_real_paid_order_items(): void
    {
        $u = User::factory()->create();
        $p = $this->product();
        $v = ProductVariant::create([
            'product_id' => $p->id,
            'sku' => 'SKU1',
            'name' => 'Default',
            'price_minor' => 100000,
            'option_values' => [],
            'is_default' => true,
            'is_active' => true,
        ]);
        $cart = Cart::create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $u->id,
            'status' => CartStatus::Converted,
            'currency' => 'PKR',
        ]);
        $session = CheckoutSession::create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $u->id,
            'cart_id' => $cart->id,
            'idempotency_key' => 'personalization-buy-again-001',
            'status' => CheckoutStatus::Converted,
            'currency' => 'PKR',
            'address_snapshot' => [
                'recipient_name' => $u->name,
                'phone' => '0300',
                'line1' => 'Test',
                'city' => 'Lahore',
                'country_code' => 'PK',
            ],
            'shipping_method' => 'standard',
            'payment_method' => 'cod',
            'subtotal_minor' => 100000,
            'shipping_minor' => 0,
            'discount_minor' => 0,
            'coin_redemption_minor' => 0,
            'total_minor' => 100000,
            'expires_at' => now()->addMinute(),
            'converted_at' => now(),
        ]);
        $o = Order::create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $u->id,
            'checkout_session_id' => $session->id,
            'status' => OrderStatus::Delivered,
            'payment_status' => PaymentStatus::Paid,
            'payment_method' => 'cod',
            'currency' => 'PKR',
            'subtotal_minor' => 100000,
            'shipping_minor' => 0,
            'discount_minor' => 0,
            'coin_redemption_coins' => 0,
            'coin_redemption_minor' => 0,
            'total_minor' => 100000,
            'refunded_minor' => 0,
            'cash_refunded_minor' => 0,
            'coin_refunded_coins' => 0,
            'placed_at' => now(),
            'delivered_at' => now(),
        ]);
        $vo = $o->vendorOrders()->create([
            'public_id' => (string) Str::ulid(),
            'vendor_id' => $p->vendor_id,
            'status' => OrderStatus::Delivered,
            'currency' => 'PKR',
            'subtotal_minor' => 100000,
            'shipping_minor' => 0,
            'discount_minor' => 0,
            'total_minor' => 100000,
            'commission_bps' => 1000,
            'platform_commission_minor' => 10000,
            'seller_payable_minor' => 90000,
            'delivered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $o->id,
            'vendor_order_id' => $vo->id,
            'product_id' => $p->id,
            'product_variant_id' => $v->id,
            'product_name' => $p->name,
            'variant_name' => 'Default',
            'sku' => 'SKU1',
            'quantity' => 1,
            'returned_quantity' => 0,
            'refunded_quantity' => 0,
            'currency' => 'PKR',
            'unit_price_minor' => 100000,
            'line_total_minor' => 100000,
            'selected_options' => [],
            'metadata' => [],
        ]);

        $this->actingAs($u)->getJson('/api/v1/buy-again')->assertOk()->assertJsonPath('data.items.0.product.slug', $p->slug);
    }

    /** Verifies recommendations are public and personalized when signed in. */
    public function test_recommendations_are_public_and_personalized_when_signed_in(): void
    {
        $p = $this->product();
        $this->getJson('/api/v1/recommendations')->assertOk()->assertJsonPath('data.personalized', false);
        $u = User::factory()->create();
        $this->actingAs($u)->postJson("/api/v1/wishlist/products/{$p->slug}");
        $this->actingAs($u)->getJson('/api/v1/recommendations')->assertOk()->assertJsonPath('data.personalized', true);
    }

    /** Verifies seller cannot upload media to another vendor product. */
    public function test_seller_cannot_upload_media_to_another_vendor_product(): void
    {
        Storage::fake('public');
        $seller = User::factory()->create();
        $this->product($seller);
        $other = User::factory()->create();
        $p = $this->product($other);
        $this->actingAs($seller)->post(
            "/api/v1/vendor/products/{$p->slug}/media",
            ['file' => UploadedFile::fake()->image('a.jpg')],
            ['Accept' => 'application/json'],
        )->assertNotFound();
    }

    /** Verifies managed media rejects non image file. */
    public function test_managed_media_rejects_non_image_file(): void
    {
        Storage::fake('public');
        $seller = User::factory()->create();
        $p = $this->product($seller);
        $this->actingAs($seller)->post(
            "/api/v1/vendor/products/{$p->slug}/media",
            ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')],
            ['Accept' => 'application/json'],
        )->assertUnprocessable();
    }

    /** Verifies managed media stores hash and never accepts client path. */
    public function test_managed_media_stores_hash_and_never_accepts_client_path(): void
    {
        Storage::fake('public');
        $seller = User::factory()->create();
        $p = $this->product($seller);
        $this->actingAs($seller)->post(
            "/api/v1/vendor/products/{$p->slug}/media",
            ['file' => UploadedFile::fake()->image('a.jpg')],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $this->assertDatabaseCount('product_media_assets', 1);
        $asset = $p->mediaAssets()->first();
        $this->assertSame(64, strlen($asset->sha256));
        $this->assertStringStartsWith('products/'.$p->public_id.'/', $asset->path);
    }

    /** Verifies seller analytics is vendor scoped and excludes owner views. */
    public function test_seller_analytics_is_vendor_scoped_and_excludes_owner_views(): void
    {
        $seller = User::factory()->create();
        $p = $this->product($seller);
        $other = $this->product();
        $customer = User::factory()->create();
        $this->actingAs($seller)->postJson("/api/v1/products/{$p->slug}/views");
        $this->actingAs($customer)->postJson("/api/v1/products/{$p->slug}/views");
        $this->actingAs($customer)->postJson("/api/v1/products/{$other->slug}/views");
        $this->actingAs($seller)->getJson('/api/v1/vendor/analytics')->assertOk()->assertJsonPath('data.summary.views', 1);
    }
}
