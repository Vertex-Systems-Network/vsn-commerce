<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Defines the BrowserE2EContractTest class and its project responsibilities. */
class BrowserE2EContractTest extends TestCase
{
    /** Handles source for the milestone atbrowser e2 etest workflow. */
    private function source(string $path): string
    {
        return file_get_contents(base_path($path)) ?: '';
    }

    /** Verifies playwright browser matrix and failure evidence are configured. */
    public function test_playwright_browser_matrix_and_failure_evidence_are_configured(): void
    {
        $config = $this->source('playwright.config.js');
        $this->assertStringContainsString("name: 'chromium'", $config);
        $this->assertStringContainsString("name: 'mobile-chromium'", $config);
        $this->assertStringContainsString("name: 'firefox-smoke'", $config);
        $this->assertStringContainsString("name: 'webkit-smoke'", $config);
        $this->assertStringContainsString("trace: 'on-first-retry'", $config);
        $this->assertStringContainsString("screenshot: 'only-on-failure'", $config);
        $this->assertStringContainsString("video: 'retain-on-failure'", $config);
    }

    /** Verifies e2e database reset has destructive database guard. */
    public function test_e2e_database_reset_has_destructive_database_guard(): void
    {
        foreach (['scripts/e2e-server.php', 'scripts/e2e-reset.php'] as $path) {
            $source = $this->source($path);
            $this->assertStringContainsString('str_contains($safeName, \'e2e\')', $source);
            $this->assertStringContainsString('str_contains($safeName, \'test\')', $source);
            $this->assertStringContainsString('migrate:fresh --seed --force --no-interaction', $source);
        }
    }

    /** Verifies browser specs cover all daily roles and core commerce workflows. */
    public function test_browser_specs_cover_all_daily_roles_and_core_commerce_workflows(): void
    {
        $auth = $this->source('e2e/auth-rbac.spec.js');
        foreach (['customer', 'seller', 'support', 'moderator', 'finance', 'admin', 'super admin'] as $role) {
            $this->assertStringContainsString($role, $auth);
        }
        $this->assertStringContainsString('Proceed to checkout', $this->source('e2e/customer-checkout.spec.js'));
        $this->assertStringContainsString('Create shipment', $this->source('e2e/seller-operations.spec.js'));
        $admin = $this->source('e2e/admin-operations.spec.js');
        $this->assertStringContainsString('Mark paid', $admin);
        $this->assertStringContainsString('Approve selected quantities', $admin);
        $this->assertStringContainsString('Resolve', $admin);
    }

    /** Verifies mobile browser flow and horizontal overflow assertion exist. */
    public function test_mobile_browser_flow_and_horizontal_overflow_assertion_exist(): void
    {
        $source = $this->source('e2e/mobile.spec.js');
        $this->assertStringContainsString('@mobile', $source);
        $this->assertStringContainsString('Open menu', $source);
        $this->assertStringContainsString('Open account navigation', $source);
        $this->assertStringContainsString('scrollWidth', $source);
        $this->assertStringContainsString('clientWidth', $source);
    }

    /** Verifies ci runs chromium browser e2e and uploads failure artifacts. */
    public function test_ci_runs_chromium_browser_e2e_and_uploads_failure_artifacts(): void
    {
        $ci = $this->source('.github/workflows/ci.yml');
        $this->assertStringContainsString('browser-e2e:', $ci);
        $this->assertStringContainsString('npx playwright install --with-deps chromium', $ci);
        $this->assertStringContainsString('npx playwright test --project=chromium --project=mobile-chromium', $ci);
        $this->assertStringContainsString('playwright-report/', $ci);
        $this->assertStringContainsString('runtime-artifacts/playwright-junit.xml', $ci);
    }

    /** Verifies package lock stays aligned while playwright is bootstrapped without save. */
    public function test_package_lock_stays_aligned_while_playwright_is_bootstrapped_without_save(): void
    {
        $package = json_decode($this->source('package.json'), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode($this->source('package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($package['dependencies'] ?? [], $lock['packages']['']['dependencies'] ?? []);
        $this->assertSame($package['devDependencies'] ?? [], $lock['packages']['']['devDependencies'] ?? []);
        $this->assertStringContainsString('@playwright/test@1.62.0', $package['scripts']['e2e:bootstrap']);
        $this->assertStringContainsString('--no-save', $package['scripts']['e2e:bootstrap']);
        $this->assertStringContainsString('--package-lock=false', $package['scripts']['e2e:bootstrap']);
    }
}
