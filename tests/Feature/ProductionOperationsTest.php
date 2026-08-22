<?php
namespace Tests\Feature;
use App\Domain\Operations\Services\HeartbeatService;
use App\Enums\UserRole;
use App\Jobs\QueueHeartbeatJob;
use App\Models\BackupRun;
use App\Models\OperationalHeartbeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
/** Defines the ProductionOperationsTest class and its project responsibilities. */
class ProductionOperationsTest extends TestCase
{
 use RefreshDatabase;
 /** Verifies operational tables exist. */
 public function test_operational_tables_exist():void{$this->assertTrue(Schema::hasTable('failed_jobs'));$this->assertTrue(Schema::hasTable('operational_heartbeats'));$this->assertTrue(Schema::hasTable('backup_runs'));}
 /** Verifies public liveness does not expose environment secrets. */
 public function test_public_liveness_does_not_expose_environment_secrets():void{$r=$this->getJson('/api/v1/health')->assertOk()->json('data');$this->assertSame('ok',$r['status']);$this->assertArrayNotHasKey('environment',$r);$this->assertArrayNotHasKey('database',$r);}
 /** Verifies readiness response is sanitized. */
 public function test_readiness_response_is_sanitized():void{$r=$this->getJson('/api/v1/health/ready');$this->assertContains($r->status(),[200,503]);$json=$r->json('data');$this->assertArrayHasKey('checks',$json);$this->assertArrayNotHasKey('error',$json['checks']['database']);}
 /** Verifies admin can view detailed operations but customer cannot. */
 public function test_admin_can_view_detailed_operations_but_customer_cannot():void{$admin=User::factory()->create(['role'=>UserRole::Admin]);$this->actingAs($admin)->getJson('/api/v1/admin/system/operations')->assertOk()->assertJsonStructure(['data'=>['health','launchGate','backups','failedJobs']]);$this->actingAs(User::factory()->create(['role'=>UserRole::Customer]))->getJson('/api/v1/admin/system/operations')->assertForbidden();}
 /** Verifies scheduler heartbeat is upserted. */
 public function test_scheduler_heartbeat_is_upserted():void{app(HeartbeatService::class)->beat('scheduler',['test'=>true]);app(HeartbeatService::class)->beat('scheduler',['test'=>false]);$this->assertSame(1,OperationalHeartbeat::query()->where('name','scheduler')->count());}
 /** Verifies queue heartbeat job targets critical queue. */
 public function test_queue_heartbeat_job_targets_critical_queue():void{Queue::fake();QueueHeartbeatJob::dispatch();Queue::assertPushedOn('critical',QueueHeartbeatJob::class);}
 /** Verifies backup service is safe off by default. */
 public function test_backup_service_is_safe_off_by_default():void{config(['vsn.operations.backups.enabled'=>false]);$this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);app(\App\Domain\Operations\Services\PostgresBackupService::class)->create();}
 /** Verifies completed backup artifact metadata is not exposed by public health. */
 public function test_completed_backup_artifact_metadata_is_not_exposed_by_public_health():void{$run=BackupRun::query()->create(['public_id'=>(string)\Illuminate\Support\Str::ulid(),'status'=>'completed','storage_disk'=>'local','storage_path'=>'backups/private.dump','sha256'=>str_repeat('a',64),'size_bytes'=>10,'started_at'=>now(),'completed_at'=>now()]);$this->getJson('/api/v1/health')->assertJsonMissing(['storage_path'=>$run->storage_path]);}
 /** Verifies api response has request correlation and security headers. */
 public function test_api_response_has_request_correlation_and_security_headers():void{$this->withHeader('X-Request-ID','test-req-123')->getJson('/api/v1/health')->assertOk()->assertHeader('X-Request-ID','test-req-123')->assertHeader('X-Content-Type-Options','nosniff')->assertHeader('X-Frame-Options','DENY');}
 /** Verifies critical operational indexes are present after migration. */
 public function test_critical_operational_indexes_are_present_after_migration():void{$r=app(\App\Domain\Operations\Services\DatabaseIndexAuditService::class)->execute();$this->assertTrue($r['supported']);$this->assertTrue($r['ok'],json_encode($r));}
 /** Verifies backup refuses public storage even when enabled. */
 public function test_backup_refuses_public_storage_even_when_enabled():void{config(['vsn.operations.backups.enabled'=>true,'vsn.operations.backups.disk'=>'public']);$this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);app(\App\Domain\Operations\Services\PostgresBackupService::class)->create();}
}
