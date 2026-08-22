<?php
namespace Tests\Feature;
use App\Enums\ProductStatus;
use App\Models\Category;
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
/** Defines the CatalogSearchAndAlertsTest class and its project responsibilities. */
class CatalogSearchAndAlertsTest extends TestCase
{
    use RefreshDatabase;
    /** Handles seed catalog for the catalog search and alerts test workflow. */
    private function seedCatalog():array{$seller=User::factory()->create(['role'=>'seller']);$buyer=User::factory()->create();$admin=User::factory()->create(['role'=>'admin']);$vendor=Vendor::create(['owner_user_id'=>$seller->id,'name'=>'Seller','slug'=>'seller','status'=>'active','commission_bps'=>1000]);$cat=Category::create(['name'=>'Phones','slug'=>'phones','is_active'=>true]);$p=Product::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'category_id'=>$cat->id,'sku'=>'PHONE-1','slug'=>'phone-one','name'=>'Phone One','status'=>ProductStatus::Published,'currency'=>'PKR','base_price_minor'=>100000,'rating'=>4.5]);$v=ProductVariant::create(['product_id'=>$p->id,'sku'=>'PHONE-1-A','name'=>'Default','is_default'=>true,'is_active'=>true]);$w=Warehouse::create(['code'=>'T','name'=>'Test','is_active'=>true]);Inventory::create(['warehouse_id'=>$w->id,'product_variant_id'=>$v->id,'on_hand'=>5,'reserved'=>0,'safety_stock'=>0]);return compact('seller','buyer','admin','vendor','cat','p','v');}
    /** Verifies public search only returns published products. */
    public function test_public_search_only_returns_published_products():void{$x=$this->seedCatalog();Product::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$x['vendor']->id,'category_id'=>$x['cat']->id,'slug'=>'secret','name'=>'Secret Draft','status'=>ProductStatus::Draft,'currency'=>'PKR','base_price_minor'=>100]);$r=$this->getJson('/api/v1/products?q=Phone');$r->assertOk()->assertJsonPath('data.meta.total',1)->assertJsonPath('data.items.0.slug','phone-one');}
    /** Verifies buyer can create and remove price alert. */
    public function test_buyer_can_create_and_remove_price_alert():void{$x=$this->seedCatalog();Sanctum::actingAs($x['buyer']);$r=$this->postJson('/api/v1/products/phone-one/alerts',['type'=>'price_drop']);$r->assertOk();$id=$r->json('data.id');$this->getJson('/api/v1/product-alerts')->assertOk()->assertJsonCount(1,'data');$this->deleteJson('/api/v1/product-alerts/'.$id)->assertOk();}
    /** Verifies duplicate alert updates same record. */
    public function test_duplicate_alert_updates_same_record():void{$x=$this->seedCatalog();Sanctum::actingAs($x['buyer']);$this->postJson('/api/v1/products/phone-one/alerts',['type'=>'price_drop']);$this->postJson('/api/v1/products/phone-one/alerts',['type'=>'price_drop']);$this->assertDatabaseCount('product_alerts',1);}
    /** Verifies target price must be lower than current price. */
    public function test_target_price_must_be_lower_than_current_price():void{$x=$this->seedCatalog();Sanctum::actingAs($x['buyer']);$this->postJson('/api/v1/products/phone-one/alerts',['type'=>'price_drop','targetPriceMinor'=>100000])->assertStatus(422);}
    /** Verifies seller catalog is vendor scoped. */
    public function test_seller_catalog_is_vendor_scoped():void{$x=$this->seedCatalog();Sanctum::actingAs($x['seller']);$this->getJson('/api/v1/vendor/catalog')->assertOk()->assertJsonPath('data.meta.total',1);}
    /** Verifies seller published edit moves product to pending review. */
    public function test_seller_published_edit_moves_product_to_pending_review():void{$x=$this->seedCatalog();Sanctum::actingAs($x['seller']);$this->putJson('/api/v1/vendor/products/phone-one',['name'=>'Phone One Updated'])->assertOk()->assertJsonPath('data.status','pending_review');}
    /** Verifies admin can publish pending product. */
    public function test_admin_can_publish_pending_product():void{$x=$this->seedCatalog();$x['p']->update(['status'=>ProductStatus::PendingReview]);Sanctum::actingAs($x['admin']);$this->postJson('/api/v1/admin/products/phone-one/review',['status'=>'published'])->assertOk()->assertJsonPath('data.status','published');}
    /** Verifies customer cannot open admin catalog. */
    public function test_customer_cannot_open_admin_catalog():void{$x=$this->seedCatalog();Sanctum::actingAs($x['buyer']);$this->getJson('/api/v1/admin/catalog')->assertForbidden();}
    /** Verifies seller stock adjustment writes inventory movement. */
    public function test_seller_stock_adjustment_writes_inventory_movement():void{$x=$this->seedCatalog();Sanctum::actingAs($x['seller']);$this->putJson('/api/v1/vendor/variants/'.$x['v']->id.'/stock',['onHand'=>9])->assertOk()->assertJsonPath('data.onHand',9);$this->assertDatabaseHas('inventory_movements',['reference_type'=>'catalog_adjustment','on_hand_delta'=>4]);}
    /** Verifies negative stock is rejected. */
    public function test_negative_stock_is_rejected():void{$x=$this->seedCatalog();Sanctum::actingAs($x['seller']);$this->putJson('/api/v1/vendor/variants/'.$x['v']->id.'/stock',['onHand'=>-1])->assertStatus(422);}
}
