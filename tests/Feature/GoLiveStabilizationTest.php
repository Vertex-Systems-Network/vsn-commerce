<?php

namespace Tests\Feature;

use App\Domain\Operations\Services\GoLiveStabilizationService;
use App\Domain\Operations\Services\IncidentManagementService;
use App\Domain\Operations\Services\LaunchGateService;
use App\Domain\Operations\Services\OperationalHealthService;
use App\Domain\Operations\Services\ProductionAcceptanceService;
use App\Enums\UserRole;
use App\Models\DeploymentRun;
use App\Models\FinanceReconciliationRun;
use App\Models\GoLiveObservation;
use App\Models\GoLiveStabilizationSignoff;
use App\Models\GoLiveWindow;
use App\Models\ProductionAcceptanceRun;
use App\Models\ReleaseCandidateManifest;
use App\Models\User;
use App\Security\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Mockery;
use Tests\TestCase;

/** Defines the GoLiveStabilizationTest class and its project responsibilities. */
class GoLiveStabilizationTest extends TestCase
{
    use RefreshDatabase;

    /** Updates up. */
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'vsn.operations.release'=>'0.34.0',
            'vsn.payments.methods.card.enabled'=>false,
            'vsn.shipping_methods.standard.enabled'=>false,
            'vsn.shipping_methods.express.enabled'=>false,
            'vsn.security.seller_payout_requires_phone'=>false,
            'vsn.security.seller_payout_requires_identity'=>false,
            'vsn.notifications.email_provider'=>'laravel_mail',
            'vsn.go_live.require_distinct_signers'=>true,
            'vsn.go_live.required_signoffs'=>['operations','finance','business_owner'],
            'vsn.go_live.stabilization_minutes'=>15,
            'vsn.go_live.required_healthy_observations'=>2,
        ]);
    }

    /** Verifies az schema and one active environment guard exist. */
    public function test_az_schema_and_one_active_environment_guard_exist(): void
    {
        foreach(['go_live_windows','go_live_observations','go_live_stabilization_signoffs'] as $table)$this->assertTrue(\Schema::hasTable($table));
        $this->assertTrue(\Schema::hasColumn('go_live_windows','active_environment'));
    }

    /** Verifies sealed candidate can open one monitored window. */
    public function test_sealed_candidate_can_open_one_monitored_window(): void
    {
        [$manifest]=$this->releaseCandidate();
        FinanceReconciliationRun::create(['public_id'=>(string)Str::ulid(),'status'=>'clean','issues_count'=>0,'started_at'=>now()->subMinute(),'completed_at'=>now(),'summary'=>[]]);
        $acceptance=Mockery::mock(ProductionAcceptanceService::class);
        $acceptance->shouldReceive('goLiveStatus')->twice()->andReturn(['ready'=>true,'releaseCandidate'=>['id'=>$manifest->public_id]]);
        $health=Mockery::mock(OperationalHealthService::class);$health->shouldReceive('snapshot')->andReturn(['status'=>'ready','checks'=>[]]);
        $launch=Mockery::mock(LaunchGateService::class);$launch->shouldReceive('evaluate')->andReturn(['ready'=>true,'blockersCount'=>0,'warningsCount'=>0]);
        $incidents=Mockery::mock(IncidentManagementService::class);
        $service=new GoLiveStabilizationService($acceptance,$health,$launch,$incidents);
        $window=$service->open(null);
        $this->assertSame('monitoring',$window->status);
        $this->assertSame(app()->environment(),$window->active_environment);
        $this->assertDatabaseCount('go_live_observations',1);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->open(null);
    }

    /** Verifies consecutive healthy evidence requires distinct authorized stabilization signers. */
    public function test_consecutive_healthy_evidence_requires_distinct_authorized_stabilization_signers(): void
    {
        $window=$this->monitoringWindow();
        $window->forceFill(['stabilization_due_at'=>now()->subMinute()])->save();
        $this->healthyObservation($window,1,now()->subMinutes(2));$this->healthyObservation($window,2,now());
        $service=app(GoLiveStabilizationService::class);
        $this->assertTrue($service->status($window)['readyForSignoff']);
        $ops=User::factory()->create(['role'=>UserRole::Admin->value]);
        $finance=User::factory()->create(['role'=>UserRole::Finance->value]);
        $owner=User::factory()->create(['role'=>UserRole::SuperAdmin->value]);
        $service->sign($window,$ops,'operations','approved','Operations stable.');
        $service->sign($window,$finance,'finance','approved','Reconciliation clean.');
        $state=$service->sign($window,$owner,'business_owner','approved','Business metrics accepted.');
        $this->assertTrue($state['stable']);
        $this->assertSame('stable',$window->fresh()->status);
        $this->assertNull($window->fresh()->active_environment);
    }

    /** Verifies same user cannot sign two post launch areas when distinct signers are required. */
    public function test_same_user_cannot_sign_two_post_launch_areas_when_distinct_signers_are_required(): void
    {
        $window=$this->monitoringWindow();$window->forceFill(['stabilization_due_at'=>now()->subMinute()])->save();
        $this->healthyObservation($window,1,now()->subMinute());$this->healthyObservation($window,2,now());
        $admin=User::factory()->create(['role'=>UserRole::Admin->value]);$service=app(GoLiveStabilizationService::class);
        $service->sign($window,$admin,'operations','approved');
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->sign($window,$admin,'finance','approved');
    }

    /** Verifies rejected stabilization signoff is terminal. */
    public function test_rejected_stabilization_signoff_is_terminal(): void
    {
        $window=$this->monitoringWindow();$window->forceFill(['stabilization_due_at'=>now()->subMinute()])->save();
        $this->healthyObservation($window,1,now()->subMinute());$this->healthyObservation($window,2,now());
        $admin=User::factory()->create(['role'=>UserRole::Admin->value]);
        app(GoLiveStabilizationService::class)->sign($window,$admin,'operations','rejected','Rollback recommended.');
        $this->assertSame('failed',$window->fresh()->status);
        $this->assertNull($window->fresh()->active_environment);
    }

    /** Verifies observations and stabilization signoffs are immutable. */
    public function test_observations_and_stabilization_signoffs_are_immutable(): void
    {
        $window=$this->monitoringWindow();$observation=$this->healthyObservation($window,1,now());
        try{$observation->update(['status'=>'blocked']);$this->fail('Observation mutation should fail.');}catch(LogicException){$this->addToAssertionCount(1);}
        $signoff=GoLiveStabilizationSignoff::create(['go_live_window_id'=>$window->id,'area'=>'operations','signed_by_user_id'=>User::factory()->create()->id,'decision'=>'approved','evidence'=>[],'signed_at'=>now()]);
        $this->expectException(LogicException::class);$signoff->delete();
    }

    /** Verifies finance rbac can view and sign acceptance but cannot manage or seal. */
    public function test_finance_rbac_can_view_and_sign_acceptance_but_cannot_manage_or_seal(): void
    {
        $finance=User::factory()->create(['role'=>UserRole::Finance->value]);
        $this->assertTrue(Rbac::allows($finance,'acceptance.view'));
        $this->assertTrue(Rbac::allows($finance,'acceptance.sign'));
        $this->assertFalse(Rbac::allows($finance,'acceptance.manage'));
        $this->assertFalse(Rbac::allows($finance,'acceptance.seal'));
    }

    /** Verifies rollback record closes active window without reversing database evidence. */
    public function test_rollback_record_closes_active_window_without_reversing_database_evidence(): void
    {
        $window=$this->monitoringWindow();$admin=User::factory()->create(['role'=>UserRole::Admin->value]);
        $state=app(GoLiveStabilizationService::class)->rolledBack($window,$admin,'0.33.0','Application symlink restored after payment incident.');
        $this->assertSame('rolled_back',$state['status']);
        $this->assertNotNull($window->fresh()->rolled_back_at);
        $this->assertStringContainsString('0.33.0',(string)$window->fresh()->close_note);
    }

    /** Handles release candidate for the milestone azgo live stabilization test workflow. */
    private function releaseCandidate(): array
    {
        $deployment=DeploymentRun::create(['public_id'=>(string)Str::ulid(),'environment'=>app()->environment(),'release'=>'0.34.0','artifact_sha256'=>str_repeat('a',64),'composer_lock_sha256'=>str_repeat('b',64),'npm_lock_sha256'=>str_repeat('c',64),'status'=>'completed','phase'=>'complete','migration_batch_after'=>1,'maintenance_used'=>true,'started_at'=>now()->subHour(),'completed_at'=>now()->subMinutes(30)]);
        $acceptance=ProductionAcceptanceRun::create(['public_id'=>(string)Str::ulid(),'deployment_run_id'=>$deployment->id,'release'=>'0.34.0','environment'=>app()->environment(),'artifact_sha256'=>str_repeat('a',64),'composer_lock_sha256'=>str_repeat('b',64),'npm_lock_sha256'=>str_repeat('c',64),'verification_sha256'=>str_repeat('d',64),'evidence_sha256'=>str_repeat('e',64),'status'=>'approved','blockers_count'=>0,'warnings_count'=>0,'checks'=>[],'evaluated_at'=>now()->subMinutes(20),'approved_at'=>now()->subMinutes(10)]);
        $manifest=ReleaseCandidateManifest::create(['public_id'=>(string)Str::ulid(),'acceptance_run_id'=>$acceptance->id,'deployment_run_id'=>$deployment->id,'release'=>'0.34.0','environment'=>app()->environment(),'artifact_sha256'=>str_repeat('a',64),'composer_lock_sha256'=>str_repeat('b',64),'npm_lock_sha256'=>str_repeat('c',64),'verification_sha256'=>str_repeat('d',64),'acceptance_evidence_sha256'=>str_repeat('e',64),'manifest_sha256'=>str_repeat('f',64),'evidence'=>[],'sealed_at'=>now()->subMinutes(5)]);
        return [$manifest,$acceptance,$deployment];
    }

    /** Handles monitoring window for the milestone azgo live stabilization test workflow. */
    private function monitoringWindow(): GoLiveWindow
    {
        [$manifest,$acceptance,$deployment]=$this->releaseCandidate();
        return GoLiveWindow::create(['public_id'=>(string)Str::ulid(),'release_candidate_manifest_id'=>$manifest->id,'production_acceptance_run_id'=>$acceptance->id,'deployment_run_id'=>$deployment->id,'release'=>'0.34.0','environment'=>app()->environment(),'active_environment'=>app()->environment(),'status'=>'monitoring','artifact_sha256'=>str_repeat('a',64),'composer_lock_sha256'=>str_repeat('b',64),'npm_lock_sha256'=>str_repeat('c',64),'verification_sha256'=>str_repeat('d',64),'release_manifest_sha256'=>str_repeat('f',64),'observation_interval_minutes'=>5,'required_healthy_observations'=>2,'thresholds'=>['maxFailedJobs'=>0],'baseline'=>[],'opened_at'=>now()->subMinutes(30),'rollback_expires_at'=>now()->addHour(),'stabilization_due_at'=>now()->subMinute()]);
    }

    /** Handles healthy observation for the milestone azgo live stabilization test workflow. */
    private function healthyObservation(GoLiveWindow $window,int $sequence,$at): GoLiveObservation
    {
        return GoLiveObservation::create(['public_id'=>(string)Str::ulid(),'go_live_window_id'=>$window->id,'sequence'=>$sequence,'status'=>'healthy','blocker_count'=>0,'warning_count'=>0,'snapshot'=>[],'blockers'=>[],'warnings'=>[],'observed_at'=>$at]);
    }
}
