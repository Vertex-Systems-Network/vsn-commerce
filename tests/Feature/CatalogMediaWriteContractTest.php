<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Verifies product create/update payloads cannot bypass managed media endpoints with arbitrary URLs. */
class CatalogMediaWriteContractTest extends TestCase
{
    use RefreshDatabase;

    /** Confirms seller product creation rejects the legacy images URL array. */
    public function test_seller_product_create_rejects_arbitrary_image_urls(): void
    {
        $seller=User::factory()->create(['role'=>UserRole::Seller]);
        $vendor=Vendor::create([
            'owner_user_id'=>$seller->id,
            'name'=>'Managed Media Store',
            'slug'=>'managed-media-store',
            'status'=>'active',
            'commission_bps'=>1000,
        ]);
        $category=Category::create(['name'=>'Managed Media','slug'=>'managed-media','is_active'=>true,'sort_order'=>0]);

        $response=$this->actingAs($seller)->postJson('/api/v1/vendor/products',[
            'name'=>'Managed Image Product',
            'sku'=>'MANAGED-IMAGE-1',
            'categoryId'=>$category->id,
            'basePriceMinor'=>10000,
            'images'=>['https://example.test/should-not-be-persisted.jpg'],
            'variants'=>[[
                'name'=>'Default',
                'sku'=>'MANAGED-IMAGE-1-DEFAULT',
                'isDefault'=>true,
                'isActive'=>true,
                'stock'=>1,
            ]],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->assertDatabaseMissing('products',['vendor_id'=>$vendor->id,'sku'=>'MANAGED-IMAGE-1']);
        $this->assertDatabaseCount('product_images',0);
    }
}
