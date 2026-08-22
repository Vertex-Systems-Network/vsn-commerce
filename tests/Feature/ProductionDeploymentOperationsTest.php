<?php
namespace Tests\Feature;

use App\Domain\Operations\Services\DeploymentService;
use App\Domain\Operations\Services\IncidentManagementService;
use App\Domain\Operations\Services\ProductionConfigurationAuditService;
use App\Enums\UserRole;
use App\Models\BackupRun;
use App\Models\DeploymentRun;
use App\Models\IncidentEvent;
use App\Models\IncidentRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Defines the ProductionDeploymentOperationsTest class and its project responsibilities. */
class ProductionDeploymentOperationsTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies aw release and incident tables exist. */
    public function test_aw_release_and_incident_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('deployment_runs'));
        $this->assertTrue(Schema::hasTable('incident_events'));
    }

    /** Verifies incident lifecycle keeps append only timeline. */
    public function test_incident_lifecycle_keeps_append_only_timeline(): void
    {
        $service=app(IncidentManagementService::class);
        $incident=$service->open(null,'sev2','payments','Gateway degradation','Elevated provider errors');
        $incident=$service->status($incident,null,'investigating','Provider dashboard confirms elevated errors');
        $this->assertSame('investigating',$incident->status);
        $this->assertSame(2,IncidentEvent::query()->where('incident_record_id',$incident->id)->count());
    }

    /** Verifies incident note and resolution are audited. */
    public function test_incident_note_and_resolution_are_audited(): void
    {
        $service=app(IncidentManagementService::class);
        $incident=$service->open(null,'sev3','queue','Notification backlog');
        $incident=$service->note($incident,null,'Workers scaled from 2 to 4.');
        $incident=$service->resolve($incident,null,'Backlog drained and queue depth normalized.');
        $this->assertSame('resolved',$incident->status);
        $this->assertSame(['opened','note','resolved'],IncidentEvent::query()->where('incident_record_id',$incident->id)->orderBy('id')->pluck('event_type')->all());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->note($incident,null,'This must not mutate a resolved incident.');
    }

    /** Verifies deployment records release backup and terminal evidence. */
    public function test_deployment_records_release_backup_and_terminal_evidence(): void
    {
        config(['vsn.operations.release'=>'aw-test']);
        $backup=BackupRun::query()->create(['public_id'=>(string)Str::ulid(),'status'=>'completed','storage_disk'=>'local','storage_path'=>'backups/test.sql','sha256'=>str_repeat('a',64),'size_bytes'=>100,'started_at'=>now(),'completed_at'=>now(),'verified_at'=>now()]);
        $service=app(DeploymentService::class);
        $run=$service->begin(['release'=>'aw-test','previous_release'=>'av-test','artifact_sha256'=>str_repeat('b',64)]);
        $run=$service->attachBackup($run,$backup);
        $run=$service->phase($run,'readiness',['gate'=>'pending']);
        $run=$service->complete($run,['launchGate'=>'pass']);
        $this->assertSame('completed',$run->status);
        $this->assertSame($backup->id,$run->backup_run_id);
        $this->assertSame('av-test',$run->previous_release);
        $this->assertSame(1,DeploymentRun::query()->count());
    }

    /** Verifies admin operations exposes release incident and configuration evidence. */
    public function test_admin_operations_exposes_release_incident_and_configuration_evidence(): void
    {
        $admin=User::factory()->create(['role'=>UserRole::Admin]);
        IncidentRecord::query()->create(['public_id'=>(string)Str::ulid(),'severity'=>'sev3','type'=>'test','status'=>'open','title'=>'Test incident','started_at'=>now()]);
        DeploymentRun::query()->create(['public_id'=>(string)Str::ulid(),'environment'=>'testing','release'=>'aw-test','status'=>'completed','phase'=>'complete','started_at'=>now(),'completed_at'=>now()]);
        $this->actingAs($admin)->getJson('/api/v1/admin/system/operations')->assertOk()->assertJsonStructure(['data'=>['health','configuration','launchGate','backups','deployments','incidents','failedJobs']]);
    }

    /** Verifies security config keeps provider and web hardening keys in one tree. */
    public function test_security_config_keeps_provider_and_web_hardening_keys_in_one_tree(): void
    {
        $security=(array)config('vsn.security');
        $this->assertArrayHasKey('sms_provider',$security);
        $this->assertArrayHasKey('providers',$security);
        $this->assertArrayHasKey('max_api_request_bytes',$security);
        $this->assertArrayHasKey('csp',$security);
        $this->assertArrayHasKey('uploads',$security);
    }

    /** Verifies production configuration audit has machine readable checks. */
    public function test_production_configuration_audit_has_machine_readable_checks(): void
    {
        $report=app(ProductionConfigurationAuditService::class)->audit();
        $this->assertArrayHasKey('ok',$report);
        $this->assertArrayHasKey('blockersCount',$report);
        $this->assertNotEmpty($report['checks']);
        $this->assertNotNull(collect($report['checks'])->firstWhere('name','composer_lock'));
    }
}
