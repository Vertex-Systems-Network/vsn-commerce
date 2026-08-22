<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the CartApiTest class and its project responsibilities. */
class CartApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies guest cart uses server price and persists by token. */
    public function test_guest_cart_uses_server_price_and_persists_by_token(): void
    {
        [$product] = $this->productWithStock(priceMinor: 12_500_00, stock: 5);

        $cart = $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.summary.quantity', 0)
            ->json('data');

        $token = $cart['guestToken'];
        $this->assertNotEmpty($token);

        $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/cart/items', [
                'productSlug' => $product->slug,
                'quantity' => 2,
                // Deliberately untrusted. There is no cart rule that consumes this value.
                'priceMinor' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.summary.quantity', 2)
            ->assertJsonPath('data.summary.subtotalMinor', 25_000_00)
            ->assertJsonPath('data.items.0.unitPriceMinor', 12_500_00);

        $this->withHeader('X-Cart-Token', $token)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.summary.quantity', 2);
    }

    /** Verifies cart rejects quantity above live stock. */
    public function test_cart_rejects_quantity_above_live_stock(): void
    {
        [$product] = $this->productWithStock(priceMinor: 5_000_00, stock: 2);
        $token = $this->getJson('/api/v1/cart')->json('data.guestToken');

        $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/cart/items', [
                'productId' => $product->id,
                'quantity' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quantity.0', 'Only 2 unit(s) are currently available.');
    }

    /** Verifies guest cart merges into authenticated cart. */
    public function test_guest_cart_merges_into_authenticated_cart(): void
    {
        [$product] = $this->productWithStock(priceMinor: 8_000_00, stock: 10);
        $token = $this->getJson('/api/v1/cart')->json('data.guestToken');

        $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/cart/items', [
                'productId' => $product->id,
                'quantity' => 2,
            ])
            ->assertOk();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/merge', ['guestToken' => $token])
            ->assertOk()
            ->assertJsonPath('data.summary.quantity', 2)
            ->assertJsonPath('meta.mergedQuantity', 2);

        $this->assertDatabaseHas('carts', [
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('carts', [
            'guest_token' => $token,
            'status' => 'active',
        ]);
    }

    /** Verifies cart item cannot be mutated with another guest token. */
    public function test_cart_item_cannot_be_mutated_with_another_guest_token(): void
    {
        [$product] = $this->productWithStock(priceMinor: 4_000_00, stock: 10);
        $firstToken = $this->getJson('/api/v1/cart')->json('data.guestToken');

        $firstCart = $this->withHeader('X-Cart-Token', $firstToken)
            ->postJson('/api/v1/cart/items', [
                'productId' => $product->id,
                'quantity' => 1,
            ])
            ->json('data');

        $itemId = $firstCart['items'][0]['id'];
        $secondToken = $this->withHeader('X-Cart-Token', (string) Str::uuid())
            ->getJson('/api/v1/cart')
            ->json('data.guestToken');

        $this->withHeader('X-Cart-Token', $secondToken)
            ->deleteJson("/api/v1/cart/items/{$itemId}")
            ->assertNotFound();
    }

    /** Verifies cart reports price change without trusting old snapshot. */
    public function test_cart_reports_price_change_without_trusting_old_snapshot(): void
    {
        [$product, $variant] = $this->productWithStock(priceMinor: 10_000_00, stock: 10);
        $token = $this->getJson('/api/v1/cart')->json('data.guestToken');

        $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/cart/items', [
                'productId' => $product->id,
                'quantity' => 1,
            ])
            ->assertOk();

        $variant->update(['price_minor' => 11_000_00]);

        $this->withHeader('X-Cart-Token', $token)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.priceSnapshotMinor', 10_000_00)
            ->assertJsonPath('data.items.0.unitPriceMinor', 11_000_00)
            ->assertJsonPath('data.items.0.priceChanged', true)
            ->assertJsonPath('data.summary.subtotalMinor', 11_000_00);
    }


    /** Verifies explicit product options resolve exact variant and invalid combination fails. */
    public function test_explicit_product_options_resolve_exact_variant_and_invalid_combination_fails(): void
    {
        [$product] = $this->productWithStock(
            priceMinor: 20_000_00,
            stock: 4,
            options: ['color' => 'Black', 'variant' => '256GB'],
            variantName: 'Black / 256GB',
        );
        $token = $this->getJson('/api/v1/cart')->json('data.guestToken');

        $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/cart/items', [
                'productSlug' => $product->slug,
                'selectedOptions' => ['color' => 'Black', 'variant' => '256GB'],
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.selectedOptions.color', 'Black')
            ->assertJsonPath('data.items.0.selectedOptions.variant', '256GB');

        $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/cart/items', [
                'productSlug' => $product->slug,
                'selectedOptions' => ['color' => 'White', 'variant' => '256GB'],
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.selectedOptions.0', 'The selected product option combination is unavailable.');
    }

    /** Verifies merge preserves line when stock drops after guest added it. */
    public function test_merge_preserves_line_when_stock_drops_after_guest_added_it(): void
    {
        [$product, $variant, $inventory] = $this->productWithStock(priceMinor: 7_500_00, stock: 1);
        $token = $this->getJson('/api/v1/cart')->json('data.guestToken');

        $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/cart/items', [
                'productId' => $product->id,
                'quantity' => 1,
            ])
            ->assertOk();

        $inventory->update(['on_hand' => 0]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/merge', ['guestToken' => $token])
            ->assertOk()
            ->assertJsonPath('data.summary.quantity', 1)
            ->assertJsonPath('data.summary.hasStockIssues', true)
            ->assertJsonPath('data.items.0.stockAvailable', 0);
    }

    /** Verifies cart rejects mixed currency lines. */
    public function test_cart_rejects_mixed_currency_lines(): void
    {
        [$product] = $this->productWithStock(priceMinor: 50_00, stock: 3, currency: 'USD');
        $token = $this->getJson('/api/v1/cart')->json('data.guestToken');

        $this->withHeader('X-Cart-Token', $token)
            ->postJson('/api/v1/cart/items', [
                'productId' => $product->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.currency.0', 'Products with different currencies cannot share one cart.');
    }

    /** Handles product with stock for the cart api test workflow. */
    private function productWithStock(
        int $priceMinor,
        int $stock,
        array $options = [],
        string $variantName = 'Default',
        string $currency = 'PKR',
    ): array
    {
        $product = Product::create([
            'public_id' => (string) Str::ulid(),
            'slug' => 'product-'.Str::lower(Str::random(8)),
            'name' => 'Test Product',
            'status' => ProductStatus::Published,
            'currency' => $currency,
            'base_price_minor' => $priceMinor,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'name' => $variantName,
            'option_values' => $options,
            'price_minor' => $priceMinor,
            'is_default' => true,
            'is_active' => true,
        ]);

        $warehouse = Warehouse::create([
            'code' => 'WH-'.Str::upper(Str::random(5)),
            'name' => 'Test Warehouse',
        ]);

        $inventory = Inventory::create([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'on_hand' => $stock,
            'reserved' => 0,
            'safety_stock' => 0,
        ]);

        return [$product, $variant, $inventory];
    }
}
