<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Verifies catalog media writes and reusable-library presentation stay inside their capability boundaries. */
class CatalogMediaWriteContractTest extends TestCase
{
    use RefreshDatabase;

    /** Reads the shared media-library React surface. */
    private function mediaLibrarySource(): string
    {
        $source = file_get_contents(base_path('resources/js/components/MediaLibraryPanel.jsx'));

        $this->assertIsString($source);

        return $source;
    }

    /** Reads the canonical catalog editor surface. */
    private function catalogSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/CatalogManagement.jsx'));

        $this->assertIsString($source);

        return $source;
    }

    /** Confirms seller product creation rejects the legacy images URL array. */
    public function test_seller_product_create_rejects_arbitrary_image_urls(): void
    {
        $seller = User::factory()->create(['role' => UserRole::Seller]);
        $vendor = Vendor::create([
            'owner_user_id' => $seller->id,
            'name' => 'Managed Media Store',
            'slug' => 'managed-media-store',
            'status' => 'active',
            'commission_bps' => 1000,
        ]);
        $category = Category::create(['name' => 'Managed Media', 'slug' => 'managed-media', 'is_active' => true, 'sort_order' => 0]);

        $response = $this->actingAs($seller)->postJson('/api/v1/vendor/products', [
            'name' => 'Managed Image Product',
            'sku' => 'MANAGED-IMAGE-1',
            'categoryId' => $category->id,
            'basePriceMinor' => 10000,
            'images' => ['https://example.test/should-not-be-persisted.jpg'],
            'variants' => [[
                'name' => 'Default',
                'sku' => 'MANAGED-IMAGE-1-DEFAULT',
                'isDefault' => true,
                'isActive' => true,
                'stock' => 1,
            ]],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->assertDatabaseMissing('products', ['vendor_id' => $vendor->id, 'sku' => 'MANAGED-IMAGE-1']);
        $this->assertDatabaseCount('product_images', 0);
    }

    /** Admin reusable-media reads must fail closed without media.view while vendor mode stays server-scoped. */
    public function test_admin_reusable_media_read_requires_media_view(): void
    {
        $source = $this->mediaLibrarySource();

        $this->assertStringContainsString('const canRead=mode!==\'admin\'||hasPermission(\'media.view\'),canManage=mode!==\'admin\'||hasPermission(\'media.manage\');', $source);
        $this->assertStringContainsString('if(!canRead)return;', $source);
        $this->assertStringContainsString('if(!canRead)return null;', $source);
    }

    /** Admin reusable-media upload/archive presentation and handlers must require media.manage. */
    public function test_admin_reusable_media_mutations_require_media_manage(): void
    {
        $source = $this->mediaLibrarySource();

        $this->assertStringContainsString('if(!canManage||!file)return;', $source);
        $this->assertStringContainsString('if(!canManage)return;', $source);
        $this->assertStringContainsString('{canManage&&<label className="media-library-upload">', $source);
        $this->assertStringContainsString('{canManage&&(mode===\'admin\'||item.vendor)&&<Button', $source);
    }

    /** Catalog editor continues to use the shared reusable-media component so the shared capability boundary applies. */
    public function test_catalog_editor_uses_shared_media_library_boundary(): void
    {
        $source = $this->catalogSource();

        $this->assertStringContainsString('<MediaLibraryPanel mode={admin?\'admin\':\'vendor\'} compact onSelect={attachLibraryMedia}/>', $source);
        $this->assertStringContainsString('/admin/products/${productId}/media-library/${item.id}', $source);
        $this->assertStringNotContainsString('/admin/media-library', $source);
    }
}
