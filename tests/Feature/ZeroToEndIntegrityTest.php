<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Defines the ZeroToEndIntegrityTest class and its project responsibilities. */
class ZeroToEndIntegrityTest extends TestCase
{
    /** Verifies view cache configuration is present and uses non realpath compiled directory. */
    public function test_view_cache_configuration_is_present_and_uses_non_realpath_compiled_directory(): void
    {
        $source = file_get_contents(config_path('view.php')) ?: '';
        $this->assertStringContainsString("resource_path('views')", $source);
        $this->assertStringContainsString("storage_path('framework/views')", $source);
        $this->assertStringNotContainsString("'compiled' => realpath", $source);
    }

    /** Verifies demo review seed uses valid approved enum status. */
    public function test_demo_review_seed_uses_valid_approved_enum_status(): void
    {
        $source = file_get_contents(database_path('seeders/DemoEnvironmentSeeder.php')) ?: '';
        $this->assertStringContainsString('ReviewStatus::Approved', $source);
        $this->assertDoesNotMatchRegularExpression('/[\'\"]status[\'\"]\s*=>\s*[\'\"]published[\'\"]/', $source);
    }


    /** Verifies demo payout seed uses vendor settlement payout pending enum not reserved literal. */
    public function test_demo_payout_seed_uses_vendor_settlement_payout_pending_enum_not_reserved_literal(): void
    {
        $source = file_get_contents(database_path('seeders/DemoEnvironmentSeeder.php')) ?: '';
        $this->assertStringContainsString('VendorSettlementStatus::PayoutPending', $source);
        $this->assertDoesNotMatchRegularExpression('/\$settlement->forceFill\([^;]*[\'"]status[\'"]\s*=>\s*[\'"]reserved[\'"]/', $source);
    }

    /** Verifies zero to end runs global enum integrity audit. */
    public function test_zero_to_end_runs_global_enum_integrity_audit(): void
    {
        $source = file_get_contents(base_path('scripts/zero-to-end.php')) ?: '';
        $this->assertStringContainsString("'Enum integrity audit'=>'scripts/audit-enum-integrity.php'", $source);
        $this->assertFileExists(base_path('scripts/audit-enum-integrity.php'));
    }

    /** Verifies fresh source contains all laravel runtime directory placeholders. */
    public function test_fresh_source_contains_all_laravel_runtime_directory_placeholders(): void
    {
        foreach ([
            'bootstrap/cache/.gitignore',
            'storage/framework/cache/data/.gitignore',
            'storage/framework/sessions/.gitignore',
            'storage/framework/views/.gitignore',
            'storage/logs/.gitignore',
        ] as $relative) {
            $this->assertFileExists(base_path($relative), $relative);
        }
    }

    /** Verifies local rate limiter does not require redis but production does. */
    public function test_local_rate_limiter_does_not_require_redis_but_production_does(): void
    {
        $local = file_get_contents(base_path('.env.example')) ?: '';
        $production = file_get_contents(base_path('.env.production.example')) ?: '';
        $this->assertMatchesRegularExpression('/^CACHE_LIMITER_STORE=file$/m', $local);
        $this->assertMatchesRegularExpression('/^CACHE_LIMITER_STORE=redis$/m', $production);
    }

    /** Verifies mysql phpunit database credentials can be overridden by laragon environment. */
    public function test_mysql_phpunit_database_credentials_can_be_overridden_by_laragon_environment(): void
    {
        $xml = file_get_contents(base_path('phpunit.mysql.xml')) ?: '';
        foreach (['DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD'] as $name) {
            $this->assertDoesNotMatchRegularExpression('/<env name="'.preg_quote($name, '/').'"[^>]*force="true"/', $xml);
        }
    }

    /** Verifies zero to end verifier contains seed idempotency full mysql suite and cache cycle. */
    public function test_zero_to_end_verifier_contains_seed_idempotency_full_mysql_suite_and_cache_cycle(): void
    {
        $source = file_get_contents(base_path('scripts/zero-to-end.php')) ?: '';
        foreach ([
            'migrate:fresh --seed',
            'Seeder idempotency second pass',
            'test --testsuite=Unit',
            'test --configuration=phpunit.mysql.xml',
            'view:clear',
            'view:cache',
            'route:cache',
            'config:cache',
            'event:cache',
            'npm ci',
            'npm run build',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }
}
