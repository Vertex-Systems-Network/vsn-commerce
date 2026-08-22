<?php

namespace Tests\Feature;

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

/** Defines the InventoryReservationTest class and its project responsibilities. */
class InventoryReservationTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies inventory reservation is idempotent. */
    public function test_inventory_reservation_is_idempotent(): void
    {
        $user = User::factory()->create();
        $vendor = Vendor::create(['name' => 'Seller', 'slug' => 'seller', 'status' => 'active']);
        $product = Product::create([
            'public_id' => (string) Str::ulid(),
            'vendor_id' => $vendor->id,
            'sku' => 'SKU-1',
            'slug' => 'product-1',
            'name' => 'Product 1',
            'status' => 'published',
            'currency' => 'PKR',
            'base_price_minor' => 10000,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-1-A',
            'name' => 'Default',
            'is_default' => true,
        ]);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'Main']);
        $inventory = Inventory::create([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 10,
        ]);

        Sanctum::actingAs($user);

        $payload = [
            'variantId' => $variant->id,
            'quantity' => 2,
            'idempotencyKey' => 'checkout-123-item-1',
        ];

        $this->postJson('/api/v1/inventory/reserve', $payload)->assertCreated();
        $this->postJson('/api/v1/inventory/reserve', $payload)->assertCreated();

        $this->assertSame(2, $inventory->fresh()->reserved);
    }
}
