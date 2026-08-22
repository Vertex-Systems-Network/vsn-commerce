<?php

namespace Tests\Feature;

use App\Domain\Operations\Services\ProductionAcceptanceService;
use App\Enums\UserRole;
use App\Models\BackupRun;
use App\Models\DeploymentRun;
use App\Models\ProductionAcceptanceRun;
use App\Models\ProductionAcceptanceSignoff;
use App\Models\ReleaseCandidateManifest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Defines the FinalProductionAcceptanceTest class and its project responsibilities. */
class FinalProductionAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private string $verificationPath;

    /** Updates up. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->verificationPath=storage_path('framework/testing/ax-final-acceptance.json');
        File::ensureDirectoryExists(dirname($this->verificationPath));
        config([
            'vsn.operations.release'=>'ax-test-release',
            'vsn.acceptance.verification_manifest'=>$this->verificationPath,
            'vsn.acceptance.required_runtime_flags'=>['browserE2E','androidApiSmoke'],
            'vsn.acceptance.runtime_evidence_max_age_minutes'=>180,
            'vsn.acceptance.require_distinct_signers'=>true,
            'vsn.acceptance.release_candidate_manifest_path'=>storage_path('framework/testing/ax-final-rc.json'),
        ]);
        $this->seedReleaseEvidence();
    }

    /** Handles tear down for the milestone axfinal production acceptance test workflow. */
    protected function tearDown(): void
    {
        @unlink($this->verificationPath);
        @unlink(storage_path('framework/testing/ax-final-rc.json'));
        parent::tearDown();
    }

    /** Verifies ax release candidate schema exists. */
    public function test_ax_release_candidate_schema_exists(): void
    {
        $this->assertTrue(Schema::hasTable('release_candidate_manifests'));
        foreach(['deployment_run_id','artifact_sha256','verification_sha256','evidence_sha256'] as $column)$this->assertTrue(Schema::hasColumn('production_acceptance_runs',$column));
    }

    /** Verifies acceptance snapshot captures exact deployment artifact and verification hash. */
    public function test_acceptance_snapshot_captures_exact_deployment_artifact_and_verification_hash(): void
    {
        $report=app(ProductionAcceptanceService::class)->evaluate(null,true);
        $this->assertSame(0,$report['blockersCount']);
        $this->assertSame(str_repeat('a',64),$report['artifactSha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$report['verificationSha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$report['evidenceSha256']);
        $this->assertDatabaseHas('production_acceptance_runs',['public_id'=>$report['id'],'artifact_sha256'=>str_repeat('a',64)]);
    }

    /** Verifies signoff is rejected if runtime evidence changes after snapshot. */
    public function test_signoff_is_rejected_if_runtime_evidence_changes_after_snapshot(): void
    {
        $admin=User::factory()->create(['role'=>UserRole::Admin]);
        $report=app(ProductionAcceptanceService::class)->evaluate($admin->id,true);
        $run=ProductionAcceptanceRun::where('public_id',$report['id'])->firstOrFail();
        $this->writeVerification(['browserE2E'=>true,'androidApiSmoke'=>true,'changedMarker'=>true]);
        $this->expectException(ValidationException::class);
        app(ProductionAcceptanceService::class)->sign($run,$admin,'operations','approved');
    }

    /** Verifies production profile can require four distinct authorized signers. */
    public function test_production_profile_can_require_four_distinct_authorized_signers(): void
    {
        $admin=User::factory()->create(['role'=>UserRole::Admin]);
        $report=app(ProductionAcceptanceService::class)->evaluate($admin->id,true);
        $run=ProductionAcceptanceRun::where('public_id',$report['id'])->firstOrFail();
        $service=app(ProductionAcceptanceService::class);
        $service->sign($run,$admin,'operations','approved');
        $this->expectException(ValidationException::class);
        $service->sign($run->fresh(),$admin,'security_privacy','approved');
    }

    /** Verifies four distinct signoffs then super admin seal make go live ready. */
    public function test_four_distinct_signoffs_then_super_admin_seal_make_go_live_ready(): void
    {
        $operations=User::factory()->create(['role'=>UserRole::Admin]);
        $security=User::factory()->create(['role'=>UserRole::Admin]);
        $finance=User::factory()->create(['role'=>UserRole::Finance]);
        $owner=User::factory()->create(['role'=>UserRole::SuperAdmin]);
        $service=app(ProductionAcceptanceService::class);
        $report=$service->evaluate($operations->id,true);
        $run=ProductionAcceptanceRun::where('public_id',$report['id'])->firstOrFail();
        $service->sign($run,$operations,'operations','approved');
        $service->sign($run->fresh(),$security,'security_privacy','approved');
        $service->sign($run->fresh(),$finance,'finance','approved');
        $approved=$service->sign($run->fresh(),$owner,'business_owner','approved');
        $this->assertSame('approved',$approved['status']);
        $this->assertFalse($service->goLiveStatus()['ready']);
        $manifest=$service->seal($run->fresh(),$owner);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$manifest['manifestSha256']);
        $status=$service->goLiveStatus();
        $this->assertTrue($status['releaseCandidateSealed']);
        $this->assertTrue($status['ready']);
    }

    /** Verifies signoff and release candidate models are immutable. */
    public function test_signoff_and_release_candidate_models_are_immutable(): void
    {
        $operations=User::factory()->create(['role'=>UserRole::Admin]);
        $security=User::factory()->create(['role'=>UserRole::Admin]);
        $finance=User::factory()->create(['role'=>UserRole::Finance]);
        $owner=User::factory()->create(['role'=>UserRole::SuperAdmin]);
        $service=app(ProductionAcceptanceService::class);
        $report=$service->evaluate($operations->id,true);$run=ProductionAcceptanceRun::where('public_id',$report['id'])->firstOrFail();
        $service->sign($run,$operations,'operations','approved');$service->sign($run->fresh(),$security,'security_privacy','approved');$service->sign($run->fresh(),$finance,'finance','approved');$service->sign($run->fresh(),$owner,'business_owner','approved');
        $service->seal($run->fresh(),$owner);
        $signoff=ProductionAcceptanceSignoff::firstOrFail();
        try{$signoff->update(['comment'=>'mutated']);$this->fail('Signoff mutation should fail.');}catch(\LogicException){$this->addToAssertionCount(1);}
        $manifest=ReleaseCandidateManifest::firstOrFail();
        $this->expectException(\LogicException::class);$manifest->update(['release'=>'changed']);
    }

    /** Verifies release candidate seal api requires super admin. */
    public function test_release_candidate_seal_api_requires_super_admin(): void
    {
        $admin=User::factory()->create(['role'=>UserRole::Admin]);
        $run=ProductionAcceptanceRun::create(['public_id'=>(string)Str::ulid(),'release'=>'ax-test-release','environment'=>app()->environment(),'status'=>'approved','blockers_count'=>0,'warnings_count'=>0,'checks'=>[],'evaluated_at'=>now(),'approved_at'=>now()]);
        $this->actingAs($admin)->postJson("/api/v1/admin/system/acceptance/{$run->public_id}/seal")->assertForbidden();
    }

    /** Handles seed release evidence for the milestone axfinal production acceptance test workflow. */
    private function seedReleaseEvidence(): void
    {
        $backup=BackupRun::create([
            'public_id'=>(string)Str::ulid(),'kind'=>'mysql','status'=>'completed','storage_disk'=>'local','storage_path'=>'backups/ax.sql',
            'sha256'=>str_repeat('b',64),'size_bytes'=>1024,'started_at'=>now()->subMinutes(10),'completed_at'=>now()->subMinutes(8),'verified_at'=>now()->subMinutes(7),
        ]);
        DeploymentRun::create([
            'public_id'=>(string)Str::ulid(),'backup_run_id'=>$backup->id,'environment'=>app()->environment(),'release'=>'ax-test-release','previous_release'=>'ax-prev',
            'commit_sha'=>str_repeat('c',40),'artifact_sha256'=>str_repeat('a',64),'status'=>'completed','phase'=>'complete','migration_batch_before'=>1,'migration_batch_after'=>2,
            'maintenance_used'=>true,'evidence'=>[],'started_at'=>now()->subMinutes(6),'completed_at'=>now()->subMinutes(4),
        ]);
        $this->writeVerification(['browserE2E'=>true,'androidApiSmoke'=>true]);
    }

    /** Handles write verification for the milestone axfinal production acceptance test workflow. */
    private function writeVerification(array $extra): void
    {
        $payload=array_merge([
            'schema'=>'vsn-final-acceptance-evidence-v1','generatedAt'=>now()->toIso8601String(),'release'=>'ax-test-release','artifactSha256'=>str_repeat('a',64),
        ],$extra);
        File::put($this->verificationPath,json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
}
