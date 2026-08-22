<?php
namespace Tests\Feature;

use App\Domain\Operations\Services\ProductionAcceptanceService;
use App\Enums\UserRole;
use App\Models\DisasterRecoveryDrill;
use App\Models\IncidentRecord;
use App\Models\ProductionAcceptanceRun;
use App\Models\ProductionAcceptanceSignoff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Defines the ProductionAcceptanceClosureTest class and its project responsibilities. */
class ProductionAcceptanceClosureTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies final acceptance tables exist. */
    public function test_final_acceptance_tables_exist():void
    {
        foreach(['production_acceptance_runs','production_acceptance_signoffs','disaster_recovery_drills','incident_records'] as $table)$this->assertTrue(Schema::hasTable($table));
    }

    /** Verifies recent dr drill is evaluated against rto rpo. */
    public function test_recent_dr_drill_is_evaluated_against_rto_rpo():void
    {
        DisasterRecoveryDrill::query()->create(['public_id'=>(string)Str::ulid(),'status'=>'passed','rto_minutes'=>20,'rpo_minutes'=>60,'completed_at'=>now()]);
        config(['vsn.acceptance.rto_target_minutes'=>60,'vsn.acceptance.rpo_target_minutes'=>1440]);
        $r=app(ProductionAcceptanceService::class)->evaluate(null,false);
        $c=collect($r['checks'])->firstWhere('code','disaster_recovery_drill');
        $this->assertSame('pass',$c['status']);
    }

    /** Verifies open sev1 blocks acceptance check. */
    public function test_open_sev1_blocks_acceptance_check():void
    {
        IncidentRecord::query()->create(['public_id'=>(string)Str::ulid(),'severity'=>'sev1','type'=>'security','status'=>'open','title'=>'Test incident','started_at'=>now()]);
        $r=app(ProductionAcceptanceService::class)->evaluate(null,false);
        $this->assertSame('block',collect($r['checks'])->firstWhere('code','critical_incidents')['status']);
    }

    /** Verifies four authorized signoffs approve a clean frozen run. */
    public function test_four_authorized_signoffs_approve_a_clean_frozen_run():void
    {
        $admin=User::factory()->create(['role'=>UserRole::Admin]);$finance=User::factory()->create(['role'=>UserRole::Finance]);$super=User::factory()->create(['role'=>UserRole::SuperAdmin]);
        $run=ProductionAcceptanceRun::query()->create(['public_id'=>(string)Str::ulid(),'actor_user_id'=>$admin->id,'release'=>(string)config('vsn.operations.release'),'environment'=>app()->environment(),'status'=>'awaiting_signoff','blockers_count'=>0,'warnings_count'=>0,'checks'=>[],'evaluated_at'=>now()]);
        $s=app(ProductionAcceptanceService::class);
        $s->sign($run,$admin,'operations','approved');
        $s->sign($run->fresh(),$admin,'security_privacy','approved');
        $s->sign($run->fresh(),$finance,'finance','approved');
        $out=$s->sign($run->fresh(),$super,'business_owner','approved');
        $this->assertSame('approved',$out['status']);$this->assertNotNull($out['approvedAt']);$this->assertSame(4,ProductionAcceptanceSignoff::query()->count());
    }

    /** Verifies signoff is rejected when acceptance has blockers. */
    public function test_signoff_is_rejected_when_acceptance_has_blockers():void
    {
        $admin=User::factory()->create(['role'=>UserRole::Admin]);$run=ProductionAcceptanceRun::query()->create(['public_id'=>(string)Str::ulid(),'environment'=>'testing','status'=>'blocked','blockers_count'=>1,'warnings_count'=>0,'checks'=>[],'evaluated_at'=>now()]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);app(ProductionAcceptanceService::class)->sign($run,$admin,'operations','approved');
    }

    /** Verifies business owner signoff requires super admin. */
    public function test_business_owner_signoff_requires_super_admin():void
    {
        $admin=User::factory()->create(['role'=>UserRole::Admin]);$run=ProductionAcceptanceRun::query()->create(['public_id'=>(string)Str::ulid(),'environment'=>'testing','status'=>'awaiting_signoff','blockers_count'=>0,'warnings_count'=>0,'checks'=>[],'evaluated_at'=>now()]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);app(ProductionAcceptanceService::class)->sign($run,$admin,'business_owner','approved');
    }



    /** Verifies go live status rejects stale approved acceptance. */
    public function test_go_live_status_rejects_stale_approved_acceptance():void
    {
        config(['vsn.acceptance.acceptance_valid_minutes'=>60]);
        ProductionAcceptanceRun::query()->create(['public_id'=>(string)Str::ulid(),'release'=>(string)config('vsn.operations.release'),'environment'=>app()->environment(),'status'=>'approved','blockers_count'=>0,'warnings_count'=>0,'checks'=>[],'evaluated_at'=>now()->subHours(3),'approved_at'=>now()->subHours(3)]);
        $status=app(ProductionAcceptanceService::class)->goLiveStatus();
        $this->assertFalse($status['acceptanceFresh']);$this->assertFalse($status['ready']);
    }

    /** Verifies admin acceptance api is protected. */
    public function test_admin_acceptance_api_is_protected():void
    {
        $admin=User::factory()->create(['role'=>UserRole::Admin]);$customer=User::factory()->create(['role'=>UserRole::Customer]);
        $this->actingAs($admin)->getJson('/api/v1/admin/system/acceptance')->assertOk()->assertJsonStructure(['data'=>['current','drills','incidents']]);
        $this->actingAs($customer)->getJson('/api/v1/admin/system/acceptance')->assertForbidden();
    }
}
