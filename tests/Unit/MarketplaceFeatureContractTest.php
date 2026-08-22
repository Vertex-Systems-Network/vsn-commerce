<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Guards the source-level contracts for media library, seller storefront routing and admin workspace cleanup. */
class MarketplaceFeatureContractTest extends TestCase
{
    /** Confirms public and authenticated media/storefront routes remain registered. */
    public function test_media_and_storefront_routes_are_registered(): void
    {
        $routes=file_get_contents(dirname(__DIR__,2).'/routes/api.php');
        $this->assertStringContainsString("Route::get('/vendors'",$routes);
        $this->assertStringContainsString("Route::get('/vendors/{slug}'",$routes);
        $this->assertStringContainsString("Route::get('/vendor/media-library'",$routes);
        $this->assertStringContainsString("Route::get('/admin/media-library'",$routes);
        $this->assertStringContainsString("media-library/{asset}",$routes);
    }

    /** Confirms the removed dead admin migration link cannot regress into the navigation. */
    public function test_admin_sidebar_has_no_dead_migration_route(): void
    {
        $shell=file_get_contents(dirname(__DIR__,2).'/resources/js/layout/AdminShell.jsx');
        $this->assertStringNotContainsString('/admin/migration',$shell);
    }

    /** Confirms the media library is exposed in both administrator and seller interfaces. */
    public function test_media_library_ui_is_available_in_admin_seller_and_product_editor(): void
    {
        $admin=file_get_contents(dirname(__DIR__,2).'/resources/js/pages/AdminMedia.jsx');
        $seller=file_get_contents(dirname(__DIR__,2).'/resources/js/pages/VendorMedia.jsx');
        $editor=file_get_contents(dirname(__DIR__,2).'/resources/js/pages/CatalogManagement.jsx');
        $this->assertStringContainsString('MediaLibraryPanel',$admin);
        $this->assertStringContainsString('MediaLibraryPanel',$seller);
        $this->assertStringContainsString('MediaLibraryPanel',$editor);
    }

    /** Verifies that new media-library routes are covered by centralized RBAC permissions. */
    public function test_media_library_routes_have_rbac_mappings(): void
    {
        $rbac=file_get_contents(dirname(__DIR__,2).'/app/Security/Rbac.php');
        $this->assertStringContainsString('admin/(?:media|media-library)', $rbac);
        $this->assertStringContainsString('vendor/media-library', $rbac);
        $this->assertStringContainsString("seller.catalog.view", $rbac);
        $this->assertStringContainsString("media.view", $rbac);
    }
}
