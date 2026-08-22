<?php
namespace Tests\Feature;

use App\Domain\Notifications\Services\NotificationPreferenceService;
use App\Domain\Reporting\Actions\GenerateReportExport;
use App\Domain\Reporting\Actions\QueueReportExport;
use App\Domain\Reporting\Services\ReportDatasetBuilder;
use App\Domain\Reporting\Services\ReportFilterService;
use App\Domain\Reporting\Services\ReportScheduleService;
use App\Enums\UserRole;
use App\Models\ReportExport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Defines the BusinessIntelligenceReportingTest class and its project responsibilities. */
class BusinessIntelligenceReportingTest extends TestCase
{
    use RefreshDatabase;
    /** Handles finance for the business intelligence reporting test workflow. */
    private function finance():User{return User::factory()->create(['role'=>UserRole::Finance]);}

    /** Verifies reporting schema exists. */
    public function test_reporting_schema_exists():void{$this->assertTrue(Schema::hasTable('report_exports'));$this->assertTrue(Schema::hasTable('report_schedules'));}
    /** Verifies customer cannot access business intelligence. */
    public function test_customer_cannot_access_business_intelligence():void{$this->actingAs(User::factory()->create(['role'=>UserRole::Customer]))->getJson('/api/v1/admin/analytics')->assertForbidden();}
    /** Verifies finance role can view empty analytics without fake metrics. */
    public function test_finance_role_can_view_empty_analytics_without_fake_metrics():void{$this->actingAs($this->finance())->getJson('/api/v1/admin/analytics?from=2026-08-01&to=2026-08-09&currency=PKR')->assertOk()->assertJsonPath('data.analytics.commerce.orders',0)->assertJsonPath('data.analytics.commerce.paidOrderValueMinor',0);}
    /** Verifies export is queued and storage path is not exposed. */
    public function test_export_is_queued_and_storage_path_is_not_exposed():void{$u=$this->finance();$r=$this->actingAs($u)->postJson('/api/v1/admin/analytics/exports',['reportType'=>'executive_summary','filters'=>['from'=>'2026-08-01','to'=>'2026-08-09','currency'=>'PKR']])->assertAccepted();$r->assertJsonMissingPath('data.storage_path');$this->assertDatabaseHas('report_exports',['requested_by_user_id'=>$u->id,'report_type'=>'executive_summary','status'=>'queued']);}
    /** Verifies csv generation is private checksummed and downloadable. */
    public function test_csv_generation_is_private_checksummed_and_downloadable():void{Storage::fake('local');config(['vsn.reporting.export_disk'=>'local']);$u=$this->finance();$e=app(QueueReportExport::class)->execute($u,'executive_summary',['from'=>'2026-08-01','to'=>'2026-08-09']);$e=app(GenerateReportExport::class)->execute($e);$this->assertSame('ready',$e->status);$this->assertSame(64,strlen((string)$e->sha256));$this->assertTrue(Storage::disk('local')->exists($e->storage_path));$this->actingAs($u)->get("/api/v1/admin/analytics/exports/{$e->public_id}/download")->assertOk()->assertHeader('content-type','text/csv; charset=UTF-8');}
    /** Verifies csv cells are protected from spreadsheet formula injection. */
    public function test_csv_cells_are_protected_from_spreadsheet_formula_injection():void{$builder=app(ReportDatasetBuilder::class);$action=app(GenerateReportExport::class);$method=new \ReflectionMethod($action,'safeCell');$method->setAccessible(true);$this->assertSame("'=SUM(1,1)",$method->invoke($action,'=SUM(1,1)'));$this->assertSame("'@cmd",$method->invoke($action,'@cmd'));$this->assertContains('orders',$builder->types());}
    /** Verifies schedule calculates next run in selected timezone. */
    public function test_schedule_calculates_next_run_in_selected_timezone():void{$next=app(ReportScheduleService::class)->next(['cadence'=>'daily','timezone'=>'Asia/Karachi','run_at_local'=>'08:00'],CarbonImmutable::parse('2026-08-09 04:00:00','UTC'));$this->assertSame('2026-08-10 03:00',$next->format('Y-m-d H:i'));}
    /** Verifies finance user can create private monthly schedule. */
    public function test_finance_user_can_create_private_monthly_schedule():void{$u=$this->finance();$this->actingAs($u)->postJson('/api/v1/admin/analytics/schedules',['name'=>'Monthly finance','report_type'=>'finance_ledger','cadence'=>'monthly','timezone'=>'Asia/Karachi','run_at_local'=>'08:00','day_of_month'=>1,'enabled'=>true,'filters'=>['currency'=>'PKR']])->assertCreated()->assertJsonPath('data.reportType','finance_ledger');$this->assertDatabaseHas('report_schedules',['user_id'=>$u->id,'report_type'=>'finance_ledger','enabled'=>1]);}
    /** Verifies report ready notifications have a dedicated preference category. */
    public function test_report_ready_notifications_have_a_dedicated_preference_category():void{$this->assertContains('reports',NotificationPreferenceService::CATEGORIES);$u=$this->finance();$matrix=app(NotificationPreferenceService::class)->matrix($u);$this->assertTrue($matrix['reports']['in_app']);$this->assertTrue($matrix['reports']['email']);}
    /** Verifies dashboard documents promotion attribution as non causal. */
    public function test_dashboard_documents_promotion_attribution_as_non_causal():void{$u=$this->finance();$r=$this->actingAs($u)->getJson('/api/v1/admin/analytics')->assertOk();$definition=$r->json('data.analytics.definitions.promotionRoi');$this->assertStringContainsString('not causal',$definition);}
    /** Verifies finance user cannot download another finance users export. */
    public function test_finance_user_cannot_download_another_finance_users_export():void{$owner=$this->finance();$other=$this->finance();$e=app(QueueReportExport::class)->execute($owner,'executive_summary',[]);$this->actingAs($other)->get("/api/v1/admin/analytics/exports/{$e->public_id}/download")->assertForbidden();}
    /** Verifies rolling previous month filter freezes dates at run time. */
    public function test_rolling_previous_month_filter_freezes_dates_at_run_time():void{CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-09 12:00:00','Asia/Karachi'));try{$f=app(ReportFilterService::class)->resolve(['period'=>'previous_month','timezone'=>'Asia/Karachi','currency'=>'PKR']);$this->assertSame('2026-07-01',$f['from']);$this->assertSame('2026-07-31',$f['to']);$this->assertArrayNotHasKey('period',$f);}finally{CarbonImmutable::setTestNow();}}

}
