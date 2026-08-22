<?php

namespace Tests\Feature;

use App\Domain\Catalog\Services\MediaLibraryService;
use App\Domain\Catalog\Services\ProductMediaService;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\MediaLibraryAsset;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Verifies reusable media, seller storefronts and authenticated-user data isolation. */
class MarketplaceMediaStorefrontTest extends TestCase
{
    use RefreshDatabase;

    /** Confirms a seller sees its own media and global media but never another seller's private library. */
    public function test_seller_media_library_is_scoped_to_its_vendor_plus_global_assets(): void
    {
        $sellerA=User::factory()->create(['role'=>UserRole::Seller]);
        $sellerB=User::factory()->create(['role'=>UserRole::Seller]);
        $vendorA=Vendor::create(['owner_user_id'=>$sellerA->id,'name'=>'Alpha Store','slug'=>'alpha-store','status'=>'active','commission_bps'=>1000]);
        $vendorB=Vendor::create(['owner_user_id'=>$sellerB->id,'name'=>'Beta Store','slug'=>'beta-store','status'=>'active','commission_bps'=>1000]);
        $global=$this->media(null,$sellerA,'global.jpg','global');
        $own=$this->media($vendorA,$sellerA,'alpha.jpg','vendor:'.$vendorA->id);
        $foreign=$this->media($vendorB,$sellerB,'beta.jpg','vendor:'.$vendorB->id);

        $response=$this->actingAs($sellerA)->getJson('/api/v1/vendor/media-library');
        $response->assertOk();
        $ids=collect($response->json('data.items'))->pluck('id');
        $this->assertTrue($ids->contains($global->public_id));
        $this->assertTrue($ids->contains($own->public_id));
        $this->assertFalse($ids->contains($foreign->public_id));
        $this->assertNull(collect($response->json('data.items'))->firstWhere('id',$global->public_id)['uploadedBy'] ?? null);
    }

    /** Confirms reusable media attaches to a product without duplicating or deleting the library binary. */
    public function test_library_asset_can_be_attached_and_detached_without_deleting_shared_file(): void
    {
        Storage::fake('public');
        $seller=User::factory()->create(['role'=>UserRole::Seller]);
        $vendor=Vendor::create(['owner_user_id'=>$seller->id,'name'=>'Seller','slug'=>'seller-shop','status'=>'active','commission_bps'=>1000]);
        $product=Product::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'slug'=>'library-product','name'=>'Library Product','status'=>'draft','currency'=>'PKR','base_price_minor'=>10000]);
        Storage::disk('public')->put('media-library/vendors/'.$vendor->id.'/photo.jpg','binary');
        $asset=MediaLibraryAsset::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'uploaded_by_user_id'=>$seller->id,'scope_key'=>'vendor:'.$vendor->id,'disk'=>'public','path'=>'media-library/vendors/'.$vendor->id.'/photo.jpg','original_name'=>'photo.jpg','alt_text'=>'Reusable photo','mime_type'=>'image/jpeg','byte_size'=>6,'sha256'=>str_repeat('a',64),'width'=>100,'height'=>100,'visibility'=>'public','status'=>'active']);

        $attached=app(MediaLibraryService::class)->attach($product,$asset,$seller,'Product photo');
        $this->assertDatabaseHas('product_images',['product_id'=>$product->id,'media_asset_id'=>$attached->id]);
        app(ProductMediaService::class)->delete($product,$attached);
        $this->assertDatabaseMissing('product_images',['product_id'=>$product->id,'media_asset_id'=>$attached->id]);
        Storage::disk('public')->assertExists($asset->path);
        app(MediaLibraryService::class)->archive($asset->fresh());
        $this->assertDatabaseHas('media_library_assets',['id'=>$asset->id,'status'=>'archived']);
        Storage::disk('public')->assertMissing($asset->path);
    }

    /** Confirms sellers can define a unique public shop slug and receive the matching shareable route. */
    public function test_seller_can_manage_its_shareable_shop_url(): void
    {
        $seller=User::factory()->create(['role'=>UserRole::Seller]);
        $vendor=Vendor::create(['owner_user_id'=>$seller->id,'name'=>'Old Store','slug'=>'old-store','status'=>'active','commission_bps'=>1000]);
        $payload=['name'=>'My New Store','shopSlug'=>'my-new-store','storefrontEnabled'=>true,'storefrontHeadline'=>'Fresh marketplace finds','storefrontDescription'=>'Seller storefront copy','supportEmail'=>'ops@example.test','publicSupportEmail'=>'help@example.test','supportPhone'=>'+920000000','logoUrl'=>null,'returnAddress'=>'Warehouse','dispatchNote'=>'Pack carefully'];
        $response=$this->actingAs($seller)->putJson('/api/v1/vendor/settings',$payload);
        $response->assertOk()->assertJsonPath('data.vendor.shopUrl','/shop/my-new-store');
        $this->assertSame('my-new-store',$vendor->fresh()->slug);
    }

    /** Confirms public seller directory hides disabled storefronts and does not leak internal support emails. */
    public function test_public_vendor_directory_exposes_only_storefront_safe_data(): void
    {
        $owner=User::factory()->create(['role'=>UserRole::Seller]);
        Vendor::create(['owner_user_id'=>$owner->id,'name'=>'Visible Store','slug'=>'visible-store','status'=>'active','commission_bps'=>1000,'metadata'=>['storefrontEnabled'=>true,'supportEmail'=>'private@example.test']]);
        Vendor::create(['name'=>'Hidden Store','slug'=>'hidden-store','status'=>'active','commission_bps'=>1000,'metadata'=>['storefrontEnabled'=>false]]);
        $response=$this->getJson('/api/v1/vendors');
        $response->assertOk()->assertJsonFragment(['name'=>'Visible Store','shopUrl'=>'/shop/visible-store'])->assertJsonMissing(['name'=>'Hidden Store'])->assertJsonMissing(['supportEmail'=>'private@example.test']);
    }

    /** Confirms customer account records are selected from the authenticated user's relationships only. */
    public function test_customer_address_data_is_scoped_to_authenticated_user(): void
    {
        $alice=User::factory()->create(['role'=>UserRole::Customer,'name'=>'Alice']);
        $bob=User::factory()->create(['role'=>UserRole::Customer,'name'=>'Bob']);
        Address::create(['user_id'=>$alice->id,'label'=>'Alice Home','recipient_name'=>'Alice','phone'=>'1','line1'=>'A Street','city'=>'Karachi','country_code'=>'PK','is_default'=>true]);
        Address::create(['user_id'=>$bob->id,'label'=>'Bob Home','recipient_name'=>'Bob','phone'=>'2','line1'=>'B Street','city'=>'Lahore','country_code'=>'PK','is_default'=>true]);
        $response=$this->actingAs($alice)->getJson('/api/v1/addresses');
        $response->assertOk()->assertJsonFragment(['label'=>'Alice Home'])->assertJsonMissing(['label'=>'Bob Home']);
    }

    /** Creates a media-library row for an isolated seller/global scope test. */
    private function media(?Vendor $vendor, User $uploader, string $name, string $scope): MediaLibraryAsset
    {
        return MediaLibraryAsset::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor?->id,'uploaded_by_user_id'=>$uploader->id,'scope_key'=>$scope,'disk'=>'public','path'=>'test/'.$name,'original_name'=>$name,'alt_text'=>$name,'mime_type'=>'image/jpeg','byte_size'=>10,'sha256'=>hash('sha256',$scope.$name),'width'=>100,'height'=>100,'visibility'=>'public','status'=>'active']);
    }
}
