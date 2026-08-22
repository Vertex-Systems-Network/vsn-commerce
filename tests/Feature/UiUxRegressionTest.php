<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Defines the UiUxRegressionTest class and its project responsibilities. */
class UiUxRegressionTest extends TestCase
{
    /** Verifies global ux provider is mounted. */
    public function test_global_ux_provider_is_mounted(): void
    {
        $main = file_get_contents(resource_path('js/main.jsx'));
        $provider = file_get_contents(resource_path('js/components/UXProvider.jsx'));
        $this->assertStringContainsString('<UXProvider>', $main);
        $this->assertStringContainsString('ux-toast-region', $provider);
        $this->assertStringContainsString('ConfirmDialog', $provider);
    }

    /** Verifies toolkit exposes consistent loading error empty and pagination states. */
    public function test_toolkit_exposes_consistent_loading_error_empty_and_pagination_states(): void
    {
        $source = file_get_contents(resource_path('js/components/Toolkit.jsx'));
        foreach (['LoadingState', 'ErrorState', 'EmptyState', 'Pagination', 'Skeleton', 'PageHeader'] as $component) {
            $this->assertStringContainsString("function {$component}", $source);
        }
    }

    /** Verifies admin seller and account shells have mobile drawer controls. */
    public function test_admin_seller_and_account_shells_have_mobile_drawer_controls(): void
    {
        foreach (['AdminShell.jsx', 'VendorShell.jsx', 'AccountShell.jsx'] as $file) {
            $source = file_get_contents(resource_path('js/layout/'.$file));
            $this->assertStringContainsString('nav-open', $source);
            $this->assertStringContainsString('aria-expanded', $source);
        }
    }

    /** Verifies storefront has mobile navigation and semantic search form. */
    public function test_storefront_has_mobile_navigation_and_semantic_search_form(): void
    {
        $source = file_get_contents(resource_path('js/layout/Shell.jsx'));
        $this->assertStringContainsString('storefront-drawer', $source);
        $this->assertStringContainsString('role="search"', $source);
        $this->assertStringNotContainsString('document.querySelector', $source);
    }

    /** Verifies active react code does not use native alert dialogs. */
    public function test_active_react_code_does_not_use_native_alert_dialogs(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! preg_match('/\.(jsx?|tsx?)$/', $file->getFilename())) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/\balert\s*\(/', $source, $file->getPathname());
        }
    }

    /** Verifies account verification icon is imported. */
    public function test_account_verification_icon_is_imported(): void
    {
        $source = file_get_contents(resource_path('js/layout/AccountShell.jsx'));
        $this->assertMatchesRegularExpression('/import[^;]*FaIdCard[^;]*from [\'\"]react-icons\/fa[\'\"]/', $source);
    }
}
