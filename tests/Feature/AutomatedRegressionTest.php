<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Defines the AutomatedRegressionTest class and its project responsibilities. */
class AutomatedRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies public liveness endpoint stays database independent. */
    public function test_public_liveness_endpoint_stays_database_independent(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.service', 'vsn-ecommerce-api')
            ->assertJsonPath('data.status', 'ok');
    }

    /** Verifies sensitive admin and vendor surfaces require authentication. */
    public function test_sensitive_admin_and_vendor_surfaces_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->getJson('/api/v1/vendor/catalog')->assertUnauthorized();
    }

    /** Verifies cross role boundaries are enforced at runtime. */
    public function test_cross_role_boundaries_are_enforced_at_runtime(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $support = User::factory()->create(['role' => UserRole::Support]);
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $moderator = User::factory()->create(['role' => UserRole::Moderator]);

        $this->actingAs($customer)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->actingAs($support)->getJson('/api/v1/admin/finance')->assertForbidden();
        $this->actingAs($finance)->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->actingAs($moderator)->getJson('/api/v1/admin/finance')->assertForbidden();
    }

    /** Verifies test harness blocks unfaked outbound http. */
    public function test_test_harness_blocks_unfaked_outbound_http(): void
    {
        try {
            Http::get('https://unfaked-provider.invalid/health');
            $this->fail('Expected the test harness to block an unfaked outbound HTTP request.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('without a matching fake', strtolower($e->getMessage()));
        }
    }

    /** Verifies test clock starts unfrozen. */
    public function test_test_clock_starts_unfrozen(): void
    {
        $this->assertFalse(Carbon::hasTestNow());
    }

    /** Verifies critical domain test files are present. */
    public function test_critical_domain_test_files_are_present(): void
    {
        $required = [
            'AuthApiTest.php',
            'CustomerAccountApiTest.php',
            'SellerCenterApiTest.php',
            'AdminOperationalPanelTest.php',
            'CheckoutApiTest.php',
            'PaymentApiTest.php',
            'ShippingApiTest.php',
            'ReturnsRefundsApiTest.php',
            'FinancePayoutApiTest.php',
            'WalletApiTest.php',
            'KycSecurityTest.php',
            'NotificationMessagingApiTest.php',
            'RoleAccessAndAdminUiApiTest.php',
            'MySqlRuntimeCompatibilityTest.php',
        ];

        foreach ($required as $file) {
            $this->assertFileExists(base_path('tests/Feature/'.$file), "Missing critical test suite: {$file}");
        }
    }

    /** Verifies ci runs sqlite mysql and postgres application suites. */
    public function test_ci_runs_sqlite_mysql_and_postgres_application_suites(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));
        foreach (['sqlite-tests:', 'mysql-tests:', 'postgres-tests:'] as $job) {
            $this->assertStringContainsString($job, $workflow);
        }
    }
}
