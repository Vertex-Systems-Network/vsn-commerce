<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks Admin Promotions read/write presentation to effective promotion capabilities. */
class AdminPromotionCapabilityPresentationTest extends TestCase
{
    /** Reads the canonical shared Promotions React surface. */
    private function promotionSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/Promotions.jsx'));

        $this->assertIsString($source);

        return $source;
    }

    /** Reads the canonical React route table. */
    private function appSource(): string
    {
        $source = file_get_contents(base_path('resources/js/App.jsx'));

        $this->assertIsString($source);

        return $source;
    }

    /** Admin Promotions remains a view route rather than requiring manage authority at entry. */
    public function test_admin_promotions_route_remains_view_capability(): void
    {
        $this->assertStringContainsString(
            '<Route path="promotions" element={permit("promotions.view", <AdminPromotions/>)}/>',
            $this->appSource(),
        );
    }

    /** Admin write presentation and handlers must fail closed without promotions.manage. */
    public function test_admin_promotion_writes_require_manage_capability(): void
    {
        $source = $this->promotionSource();

        $this->assertStringContainsString("const canManage=!admin||hasPermission('promotions.manage');", $source);
        $this->assertStringContainsString('{canManage&&<Card><SectionHeader title="Create promotion"', $source);
        $this->assertStringContainsString("{canManage&&<div style={{display:'flex',gap:6,marginTop:6}}>", $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'if(!canManage)return;'));
    }

    /** View-only administrators retain campaign state while seller promotion writes remain unaffected. */
    public function test_read_state_and_seller_write_contract_are_retained(): void
    {
        $source = $this->promotionSource();

        $this->assertStringContainsString('const load=admin?getAdminPromotions:getSellerPromotions', $source);
        $this->assertStringContainsString('<SectionHeader title="Campaigns"', $source);
        $this->assertStringContainsString('{p.usage?.reserved||0} reserved · {p.usage?.redeemed||0} redeemed', $source);
        $this->assertStringContainsString('const canManage=!admin||', $source);
    }
}
