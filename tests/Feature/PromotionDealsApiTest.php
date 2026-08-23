<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Address;
use App\Models\Category;
use App\Models\CheckoutSession;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\PromotionCode;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the PromotionDealsApiTest class and its project responsibilities. */
class PromotionDealsApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies seller funded automatic discount reduces seller payable. */
    public function test_seller_funded_automatic_discount_reduces_seller_payable(): void
    {
        [$buyer,$address,$seller,$vendor,$product] = $this->market();
        $this->promotion($vendor, 'Seller 10', 'automatic', 'seller', 1000);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1])->assertOk();
        $session = $this->checkout($address, 'promo-seller-1')->assertJsonPath('data.totals.discountMinor', 10000)->assertJsonPath('data.totals.platformDiscountMinor', 0)->assertJsonPath('data.totals.sellerDiscountMinor', 10000)->json('data');
        $this->postJson("/api/v1/checkout/sessions/{$session['id']}/order", [])->assertOk()->assertJsonPath('data.sellerOrders.0.sellerDiscountMinor', 10000)->assertJsonPath('data.sellerOrders.0.sellerPayableMinor', 106000);
        $this->assertDatabaseHas('promotion_usages', ['status' => 'redeemed', 'seller_funded_minor' => 10000]);
    }

    /** Verifies platform funded discount preserves seller economics. */
    public function test_platform_funded_discount_preserves_seller_economics(): void
    {
        [$buyer,$address,,$vendor,$product] = $this->market();
        $this->promotion(null, 'Platform 10', 'automatic', 'platform', 1000);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1]);
        $session = $this->checkout($address, 'promo-platform-1')->assertJsonPath('data.totals.platformDiscountMinor', 10000)->json('data');
        $this->postJson("/api/v1/checkout/sessions/{$session['id']}/order", [])->assertOk()->assertJsonPath('data.sellerOrders.0.platformDiscountMinor', 10000)->assertJsonPath('data.sellerOrders.0.sellerPayableMinor', 115000);
        $this->assertDatabaseHas('vendor_orders', ['coupon_subsidy_minor' => 10000, 'seller_discount_minor' => 0]);
    }

    /** Verifies coupon usage is reserved released and can be reused. */
    public function test_coupon_usage_is_reserved_released_and_can_be_reused(): void
    {
        [$buyer,$address,,$vendor,$product] = $this->market();
        $p = $this->promotion(null, 'Code 15', 'coupon', 'platform', 1500);
        PromotionCode::create(['public_id' => (string) Str::ulid(), 'promotion_id' => $p->id, 'code' => 'SAVE15', 'status' => 'active', 'max_redemptions' => 1, 'per_user_limit' => 1]);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1]);
        $session = $this->checkout($address, 'promo-code-1', 'SAVE15')->assertJsonPath('data.totals.discountMinor', 15000)->json('data');
        $this->assertDatabaseHas('promotion_usages', ['checkout_session_id' => CheckoutSession::where('public_id', $session['id'])->value('id'), 'status' => 'reserved']);
        $this->deleteJson("/api/v1/checkout/sessions/{$session['id']}")->assertOk();
        $this->assertDatabaseHas('promotion_usages', ['status' => 'released']);
        $this->checkout($address, 'promo-code-2', 'SAVE15')->assertOk();
    }

    /** Verifies active reserved usage counts against redemption limit. */
    public function test_active_reserved_usage_counts_against_redemption_limit(): void
    {
        [$buyer,$address,,$vendor,$product] = $this->market();
        $p = $this->promotion(null, 'Limited', 'coupon', 'platform', 1000);
        PromotionCode::create(['public_id' => (string) Str::ulid(), 'promotion_id' => $p->id, 'code' => 'ONCE', 'status' => 'active', 'max_redemptions' => 1]);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1]);
        $this->checkout($address, 'limit-001', 'ONCE')->assertOk();
        $second = User::factory()->create();
        $address2 = $this->address($second);
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1]);
        $this->checkout($address2, 'limit-002', 'ONCE')->assertUnprocessable();
    }

    /** Verifies exclusive automatic promotion blocks lower priority stackable offer. */
    public function test_exclusive_automatic_promotion_blocks_lower_priority_stackable_offer(): void
    {
        [$buyer,$address,,$vendor,$product] = $this->market();
        $this->promotion(null, 'Exclusive 20', 'automatic', 'platform', 2000, ['stacking_mode' => 'exclusive', 'priority' => 50]);
        $this->promotion(null, 'Stackable 10', 'automatic', 'platform', 1000, ['stacking_mode' => 'stackable', 'priority' => 10]);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1]);
        $this->checkout($address, 'exclusive-1')->assertJsonPath('data.totals.discountMinor', 20000);
        $this->assertDatabaseCount('promotion_usages', 1);
    }

    /** Verifies seller cannot scope promotion to another sellers product. */
    public function test_seller_cannot_scope_promotion_to_another_sellers_product(): void
    {
        [$buyer,$address,$seller,$vendor,$product] = $this->market();
        $otherSeller = User::factory()->create(['role' => 'seller']);
        $otherVendor = Vendor::create(['owner_user_id' => $otherSeller->id, 'name' => 'Other', 'slug' => 'other', 'status' => 'active', 'commission_bps' => 1000]);
        $other = Product::create(['public_id' => (string) Str::ulid(), 'vendor_id' => $otherVendor->id, 'category_id' => $product->category_id, 'slug' => 'other-product', 'name' => 'Other Product', 'status' => ProductStatus::Published, 'currency' => 'PKR', 'base_price_minor' => 100000]);
        Sanctum::actingAs($seller);
        $this->postJson('/api/v1/vendor/promotions', ['name' => 'Bad scope', 'kind' => 'automatic', 'discountType' => 'percent', 'percentBps' => 1000, 'scopes' => [['type' => 'product', 'id' => $other->id]]])->assertUnprocessable();
    }

    /** Verifies admin can create shared funding campaign. */
    public function test_admin_can_create_shared_funding_campaign(): void
    {
        [$buyer,$address,,$vendor,$product] = $this->market();
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/promotions', ['name' => 'Shared Deal', 'kind' => 'automatic', 'discountType' => 'percent', 'percentBps' => 1000, 'fundingMode' => 'shared', 'platformShareBps' => 6000, 'scopes' => [['type' => 'product', 'id' => $product->id]]])->assertCreated()->assertJsonPath('data.fundingMode', 'shared')->assertJsonPath('data.platformShareBps', 6000);
    }

    /** Verifies public deals exposes only live deal campaigns. */
    public function test_public_deals_exposes_only_live_deal_campaigns(): void
    {
        [$buyer,$address,,$vendor,$product] = $this->market();
        $this->promotion(null, 'Live Flash', 'flash', 'platform', 2500, ['ends_at' => now()->addHour()]);
        $this->promotion(null, 'Paused', 'flash', 'platform', 5000, ['status' => 'paused']);
        $this->getJson('/api/v1/deals')->assertOk()->assertJsonPath('data.items.0.promotion.name', 'Live Flash')->assertJsonPath('data.items.0.dealPriceMinor', 75000);
    }

    /** Verifies shared funding is split at line level. */
    public function test_shared_funding_is_split_at_line_level(): void
    {
        [$buyer,$address,,$vendor,$product] = $this->market();
        $this->promotion(null, 'Shared Ten', 'automatic', 'shared', 1000, ['platform_share_bps' => 6000]);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1]);
        $this->checkout($address, 'shared-1')->assertJsonPath('data.totals.discountMinor', 10000)->assertJsonPath('data.totals.platformDiscountMinor', 6000)->assertJsonPath('data.totals.sellerDiscountMinor', 4000);
    }

    /** Verifies seller promotion api forces seller funding. */
    public function test_seller_promotion_api_forces_seller_funding(): void
    {
        [$buyer,$address,$seller,$vendor,$product] = $this->market();
        Sanctum::actingAs($seller);
        $this->postJson('/api/v1/vendor/promotions', ['name' => 'Seller Deal', 'kind' => 'automatic', 'discountType' => 'percent', 'percentBps' => 500, 'scopes' => [['type' => 'all']]])->assertCreated()->assertJsonPath('data.fundingMode', 'seller');
    }

    /** Verifies exhausted automatic promotion is skipped instead of blocking checkout. */
    public function test_exhausted_automatic_promotion_is_skipped_instead_of_blocking_checkout(): void
    {
        [$buyer,$address,,$vendor,$product] = $this->market();
        $this->promotion(null, 'Exhausted Auto', 'automatic', 'platform', 1000, ['max_redemptions' => 0]);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 1]);
        $this->checkout($address, 'auto-exhausted')->assertOk()->assertJsonPath('data.totals.discountMinor', 0);
    }

    /** Verifies admin cannot create marketplace wide seller funded campaign. */
    public function test_admin_cannot_create_marketplace_wide_seller_funded_campaign(): void
    {
        [$buyer,$address,,$vendor,$product] = $this->market();
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/promotions', ['name' => 'Unsafe Seller Funding', 'kind' => 'automatic', 'discountType' => 'percent', 'percentBps' => 1000, 'fundingMode' => 'seller', 'scopes' => [['type' => 'all']]])->assertUnprocessable();
    }

    /** Handles checkout for the promotion deals api test workflow. */
    private function checkout(Address $address, string $key, ?string $code = null)
    {
        $payload = ['addressId' => $address->id, 'shippingMethod' => 'standard', 'paymentMethod' => 'cod', 'idempotencyKey' => $key];
        if ($code) {
            $payload['couponCode'] = $code;
        }

        return $this->postJson('/api/v1/checkout/sessions', $payload);
    }

    /** Handles promotion for the promotion deals api test workflow. */
    private function promotion(?Vendor $vendor, string $name, string $kind, string $funding, int $bps, array $extra = []): Promotion
    {
        $p = Promotion::create(array_merge(['public_id' => (string) Str::ulid(), 'vendor_id' => $vendor?->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'kind' => $kind, 'status' => 'active', 'discount_type' => 'percent', 'percent_bps' => $bps, 'stacking_mode' => 'stackable', 'can_stack_with_coupon' => true, 'can_stack_with_review_coupon' => false, 'funding_mode' => $funding, 'platform_share_bps' => $funding === 'platform' ? 10000 : ($funding === 'seller' ? 0 : 5000), 'priority' => 0, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()], $extra));
        $p->scopes()->create(['scope_type' => 'all']);

        return $p;
    }

    /** Handles market for the promotion deals api test workflow. */
    private function market(): array
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create(['role' => 'seller']);
        $vendor = Vendor::create(['owner_user_id' => $seller->id, 'name' => 'Seller A', 'slug' => 'seller-a', 'status' => 'active', 'commission_bps' => 1000]);
        $category = Category::create(['name' => 'Phones', 'slug' => 'phones', 'is_active' => true]);
        $product = Product::create(['public_id' => (string) Str::ulid(), 'vendor_id' => $vendor->id, 'category_id' => $category->id, 'sku' => 'PROMO-1', 'slug' => 'promo-product', 'name' => 'Promo Product', 'status' => ProductStatus::Published, 'currency' => 'PKR', 'base_price_minor' => 100000]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'PROMO-1-A', 'name' => 'Default', 'price_minor' => 100000, 'is_default' => true, 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'PROMO', 'name' => 'Promo WH']);
        Inventory::create(['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 20, 'reserved' => 0, 'safety_stock' => 0]);
        $address = $this->address($buyer);

        return [$buyer, $address, $seller, $vendor, $product];
    }

    /** Handles address for the promotion deals api test workflow. */
    private function address(User $user): Address
    {
        return Address::create(['user_id' => $user->id, 'label' => 'Home', 'recipient_name' => $user->name, 'phone' => '03001234567', 'line1' => '1 Test St', 'city' => 'Lahore', 'state' => 'Punjab', 'postal_code' => '54000', 'country_code' => 'PK', 'is_default' => true]);
    }
}
