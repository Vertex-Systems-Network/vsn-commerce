<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks the client route guards to the server-authoritative data capabilities they require. */
class AdminRouteCapabilityCompositionTest extends TestCase
{
    /** Reads the canonical React route table used by the admin shell. */
    private function appRoutes(): string
    {
        $source = file_get_contents(base_path('resources/js/App.jsx'));

        $this->assertIsString($source);

        return $source;
    }

    /** The admin overview must require analytics authority before its mandatory analytics load can run. */
    public function test_admin_index_requires_analytics_view(): void
    {
        $source = $this->appRoutes();

        $this->assertStringContainsString(
            '<Route index element={permit("analytics.view", <AdminControl/>)}/>',
            $source,
        );
        $this->assertStringNotContainsString(
            '<Route index element={<AdminControl/>}/>',
            $source,
        );
    }

    /** Seller Quality must require shipping authority because its only mandatory payload is shipping quality data. */
    public function test_seller_quality_requires_shipping_view(): void
    {
        $source = $this->appRoutes();

        $this->assertStringContainsString(
            '<Route path="seller-quality" element={permit("shipping.view", <SellerQuality/>)}/>',
            $source,
        );
        $this->assertStringNotContainsString(
            '<Route path="seller-quality" element={permit("vendors.view", <SellerQuality/>)}/>',
            $source,
        );
    }
}
