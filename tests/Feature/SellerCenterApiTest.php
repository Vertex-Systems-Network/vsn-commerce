<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Defines the SellerCenterApiTest class and its project responsibilities. */
class SellerCenterApiTest extends TestCase
{
    use RefreshDatabase;

    /** Handles seller with vendor for the seller center api test workflow. */
    private function sellerWithVendor(): array
    {
        $seller = User::factory()->create(['role' => 'seller']);
        $seller->profile()->create(['timezone' => 'Asia/Karachi']);
        $vendor = Vendor::create([
            'owner_user_id' => $seller->id,
            'name' => 'Seller Test Store',
            'slug' => 'seller-test-store',
            'status' => 'active',
            'commission_bps' => 1000,
        ]);
        return [$seller, $vendor];
    }

    /** Verifies seller can open operational overview. */
    public function test_seller_can_open_operational_overview(): void
    {
        [$seller, $vendor] = $this->sellerWithVendor();

        $this->actingAs($seller)->getJson('/api/v1/vendor/overview')
            ->assertOk()
            ->assertJsonPath('data.vendor.id', $vendor->id)
            ->assertJsonPath('data.vendor.name', 'Seller Test Store')
            ->assertJsonPath('data.metrics.products', 0)
            ->assertJsonPath('data.metrics.openOrders', 0);
    }

    /** Verifies seller can update store settings without changing marketplace controls. */
    public function test_seller_can_update_store_settings_without_changing_marketplace_controls(): void
    {
        [$seller, $vendor] = $this->sellerWithVendor();

        $this->actingAs($seller)->putJson('/api/v1/vendor/settings', [
            'name' => 'Updated Store',
            'supportEmail' => 'seller-support@example.test',
            'supportPhone' => '+92 300 1234567',
            'returnAddress' => 'Warehouse Road, Lahore',
            'dispatchNote' => 'Dispatch before 4pm.',
        ])->assertOk()->assertJsonPath('data.vendor.name', 'Updated Store');

        $vendor->refresh();
        $this->assertSame('active', $vendor->status);
        $this->assertSame(1000, $vendor->commission_bps);
        $this->assertSame('seller-support@example.test', $vendor->metadata['supportEmail']);
    }

    /** Verifies customer cannot access seller area api. */
    public function test_customer_cannot_access_seller_area_api(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->getJson('/api/v1/vendor/overview')->assertForbidden();
    }

    /** Verifies admin cannot impersonate seller area through vendor routes. */
    public function test_admin_cannot_impersonate_seller_area_through_vendor_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/v1/vendor/overview')->assertForbidden();
    }

    /** Verifies seller without linked vendor is rejected. */
    public function test_seller_without_linked_vendor_is_rejected(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);
        $this->actingAs($seller)->getJson('/api/v1/vendor/settings')->assertForbidden();
    }
}
