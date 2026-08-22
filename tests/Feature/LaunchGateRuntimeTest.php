<?php
namespace Tests\Feature;

use App\Domain\Operations\Services\LaunchGateService;
use App\Enums\UserRole;
use App\Models\LaunchGateRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Defines the LaunchGateRuntimeTest class and its project responsibilities. */
class LaunchGateRuntimeTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies launch gate table exists. */
    public function test_launch_gate_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('launch_gate_runs'));
    }

    /** Verifies launch gate returns machine readable checks and can persist. */
    public function test_launch_gate_returns_machine_readable_checks_and_can_persist(): void
    {
        config(['vsn.operations.launch.require_verification_manifest'=>false]);
        $report = app(LaunchGateService::class)->evaluate(null, true);
        $this->assertArrayHasKey('ready', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertNotEmpty($report['checks']);
        $this->assertSame(1, LaunchGateRun::query()->count());
    }

    /** Verifies admin can run launch gate but customer cannot. */
    public function test_admin_can_run_launch_gate_but_customer_cannot(): void
    {
        $admin = User::factory()->create(['role'=>UserRole::Admin]);
        $customer = User::factory()->create(['role'=>UserRole::Customer]);
        $this->actingAs($admin)->getJson('/api/v1/admin/system/launch-gate')->assertOk()->assertJsonStructure(['data'=>['current'=>['status','blockersCount','warningsCount','checks']]]);
        $this->actingAs($admin)->postJson('/api/v1/admin/system/launch-gate')->assertOk()->assertJsonStructure(['data'=>['id','status','checks']]);
        $this->actingAs($customer)->postJson('/api/v1/admin/system/launch-gate')->assertForbidden();
    }

    /** Verifies missing required runtime manifest becomes a gate failure. */
    public function test_missing_required_runtime_manifest_becomes_a_gate_failure(): void
    {
        $path = base_path('runtime/test-missing-launch-manifest.json');
        File::delete($path);
        config(['vsn.operations.launch.require_verification_manifest'=>true,'vsn.operations.launch.verification_manifest'=>$path]);
        $report = app(LaunchGateService::class)->evaluate(null, false);
        $check = collect($report['checks'])->firstWhere('code','runtime_verification');
        $this->assertNotNull($check);
        // In non-production this remains a warning; production upgrades the same missing evidence to a blocker.
        $this->assertContains($check['status'], ['warning','block']);
    }

    /** Verifies complete runtime manifest passes manifest check. */
    public function test_complete_runtime_manifest_passes_manifest_check(): void
    {
        $path = base_path('runtime/test-launch-manifest.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'generatedAt'=>now()->toIso8601String(),
            'composerLock'=>true,'npmLock'=>true,
            'dependencies'=>true,'databaseMigrations'=>true,'laravelTests'=>true,
            'frontendBuild'=>true,'appSmoke'=>true,'authenticatedE2E'=>true,
            'queueHeartbeat'=>true,'schedulerHeartbeat'=>true,'backupRestoreDrill'=>true,'providerContracts'=>true,
        ]));
        try {
            config(['vsn.operations.launch.require_verification_manifest'=>true,'vsn.operations.launch.verification_manifest'=>$path]);
            $report=app(LaunchGateService::class)->evaluate(null,false);
            $check=collect($report['checks'])->firstWhere('code','runtime_verification');
            $this->assertSame('pass',$check['status']);
        } finally { File::delete($path); }
    }

    /** Verifies launch gate does not expose provider secrets. */
    public function test_launch_gate_does_not_expose_provider_secrets(): void
    {
        config(['vsn.payments.providers.sandbox.webhook_secret'=>'super-secret-value']);
        $report=app(LaunchGateService::class)->evaluate(null,false);
        $json=json_encode($report);
        $this->assertStringNotContainsString('super-secret-value',$json);
        $this->assertStringNotContainsString('vault_secret',$json);
    }
}
