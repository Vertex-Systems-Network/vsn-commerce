<?php
namespace Tests\Feature;
use App\Domain\Tax\Services\CheckoutTaxCalculator;use App\Models\{Address,CheckoutSession,CheckoutSessionItem,Product,TaxClass,TaxJurisdiction,TaxRate,User,Vendor,VendorTaxProfile};use Illuminate\Foundation\Testing\RefreshDatabase;use Illuminate\Support\Str;use Tests\TestCase;
/** Defines the TaxInvoiceApiTest class and its project responsibilities. */
class TaxInvoiceApiTest extends TestCase { use RefreshDatabase;
 /** Verifies tax jurisdiction has no hardcoded rate until admin configures one. */
 public function test_tax_jurisdiction_has_no_hardcoded_rate_until_admin_configures_one():void{$this->assertDatabaseCount('tax_rates',0);}
 /** Verifies region specific jurisdiction can override country fallback. */
 public function test_region_specific_jurisdiction_can_override_country_fallback():void{$country=TaxJurisdiction::create(['public_id'=>(string)Str::ulid(),'country_code'=>'PK','name'=>'Pakistan','status'=>'active','priority'=>10]);$region=TaxJurisdiction::create(['public_id'=>(string)Str::ulid(),'country_code'=>'PK','region_code'=>'Punjab','name'=>'Punjab','status'=>'active','priority'=>100]);$r=app(\App\Domain\Tax\Services\TaxJurisdictionResolver::class)->resolve(['country_code'=>'PK','state'=>'Punjab']);$this->assertSame($region->id,$r->id);}
 /** Verifies vendor tax identifier is encrypted and api model only needs last four. */
 public function test_vendor_tax_identifier_is_encrypted_and_api_model_only_needs_last_four():void{$u=User::factory()->create();$v=Vendor::create(['owner_user_id'=>$u->id,'name'=>'Seller','slug'=>'seller','status'=>'active']);$p=VendorTaxProfile::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$v->id,'status'=>'active','tax_identifier'=>'ABC-123456','tax_identifier_last4'=>'3456','collection_mode'=>'seller']);$raw=\DB::table('vendor_tax_profiles')->where('id',$p->id)->value('tax_identifier');$this->assertNotSame('ABC-123456',$raw);$this->assertSame('ABC-123456',$p->fresh()->tax_identifier);}
 /** Verifies tax document tables are snapshot oriented. */
 public function test_tax_document_tables_are_snapshot_oriented():void{$this->assertTrue(\Schema::hasTable('checkout_tax_lines'));$this->assertTrue(\Schema::hasTable('order_tax_lines'));$this->assertTrue(\Schema::hasTable('tax_invoices'));$this->assertTrue(\Schema::hasTable('tax_credit_notes'));}
 /** Verifies product supports tax class and inclusive price flag. */
 public function test_product_supports_tax_class_and_inclusive_price_flag():void{$this->assertTrue(\Schema::hasColumn('products','tax_class_id'));$this->assertTrue(\Schema::hasColumn('products','price_includes_tax'));}
 /** Verifies order and checkout capture added and included tax separately. */
 public function test_order_and_checkout_capture_added_and_included_tax_separately():void{$this->assertTrue(\Schema::hasColumn('checkout_sessions','tax_added_minor'));$this->assertTrue(\Schema::hasColumn('orders','tax_included_minor'));}
 /** Verifies refunds capture tax refund component. */
 public function test_refunds_capture_tax_refund_component():void{$this->assertTrue(\Schema::hasColumn('refunds','tax_refund_minor'));$this->assertTrue(\Schema::hasColumn('vendor_refund_adjustments','platform_tax_reversal_minor'));}
 /** Verifies finance has sales tax liability account. */
 public function test_finance_has_sales_tax_liability_account():void{$this->assertSame('liability.sales_tax_payable',\App\Domain\Finance\FinanceAccounts::SALES_TAX_PAYABLE);}

 /** Verifies exclusive tax math adds tax on top. */
 public function test_exclusive_tax_math_adds_tax_on_top():void{$calc=app(CheckoutTaxCalculator::class);$m=new \ReflectionMethod($calc,'compute');$out=$m->invoke($calc,10000,collect([(object)['id'=>1,'rate_bps'=>1000]]),false);$this->assertSame(10000,$out[0]['taxable']);$this->assertSame(1000,$out[0]['tax']);}
 /** Verifies inclusive tax math extracts tax from gross. */
 public function test_inclusive_tax_math_extracts_tax_from_gross():void{$calc=app(CheckoutTaxCalculator::class);$m=new \ReflectionMethod($calc,'compute');$out=$m->invoke($calc,11000,collect([(object)['id'=>1,'rate_bps'=>1000]]),true);$this->assertSame(10000,$out[0]['taxable']);$this->assertSame(1000,$out[0]['tax']);}
 /** Verifies combined inclusive rates preserve exact total without double taxable base. */
 public function test_combined_inclusive_rates_preserve_exact_total_without_double_taxable_base():void{$calc=app(CheckoutTaxCalculator::class);$m=new \ReflectionMethod($calc,'compute');$out=$m->invoke($calc,11500,collect([(object)['id'=>1,'rate_bps'=>1000],(object)['id'=>2,'rate_bps'=>500]]),true);$this->assertSame(1500,array_sum(array_column($out,'tax')));$this->assertSame(10000,$out[0]['taxable']);$this->assertSame(10000,$out[1]['taxable']);}

 /** Verifies zero rate preserves auditable tax line. */
 public function test_zero_rate_preserves_auditable_tax_line():void{$calc=app(CheckoutTaxCalculator::class);$m=new \ReflectionMethod($calc,'compute');$out=$m->invoke($calc,10000,collect([(object)['id'=>1,'rate_bps'=>0]]),false);$this->assertCount(1,$out);$this->assertSame(10000,$out[0]['taxable']);$this->assertSame(0,$out[0]['tax']);}
}
