<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks Admin Engagement read/write presentation to effective loyalty and games capabilities. */
class AdminEngagementCapabilityPresentationTest extends TestCase
{
    /** Reads the shared Admin Engagement React surface. */
    private function engagementSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/AdminEngagement.jsx'));

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

    /** Returns only the loyalty surface from the shared module. */
    private function loyaltySource(): string
    {
        $parts = explode('/** Handles admin games for the VSN Ecommerce interface. */', $this->engagementSource(), 2);
        $this->assertCount(2, $parts);

        return $parts[0];
    }

    /** Returns only the games surface from the shared module. */
    private function gamesSource(): string
    {
        $parts = explode('/** Handles admin games for the VSN Ecommerce interface. */', $this->engagementSource(), 2);
        $this->assertCount(2, $parts);

        return $parts[1];
    }

    /** Loyalty and games routes remain readable with their view capabilities. */
    public function test_engagement_routes_preserve_view_capabilities(): void
    {
        $source = $this->appSource();

        $this->assertStringContainsString(
            '<Route path="loyalty" element={permit("loyalty.view", <AdminLoyalty/>)}/>',
            $source,
        );
        $this->assertStringContainsString(
            '<Route path="games" element={permit("games.view", <AdminGames/>)}/>',
            $source,
        );
    }

    /** Loyalty mutation presentation and handlers require loyalty.manage while reads remain available. */
    public function test_loyalty_writes_require_manage_capability(): void
    {
        $source = $this->loyaltySource();

        $this->assertStringContainsString("const canManage=hasPermission('loyalty.manage');", $source);
        $this->assertGreaterThanOrEqual(4, substr_count($source, 'if(!canManage)return;'));
        $this->assertStringContainsString('action={canManage?\'Process commissions\':undefined}', $source);
        $this->assertStringContainsString('{canManage&&<Button variant="secondary" disabled={busy===\'expire\'} onClick={expire}>Process due expiries</Button>}', $source);
        $this->assertStringContainsString('{canManage&&<td><Button disabled={busy===`w${w.userId}`}', $source);
        $this->assertStringContainsString('{canManage&&<div className="button-row"><Button disabled={busy===\'process\'} onClick={process}>', $source);
        $this->assertStringContainsString('apiGet(`/admin/engagement/wallets?q=${encodeURIComponent(search)}`)', $source);
        $this->assertStringContainsString('onKeyDown={/** Inline callback for this operation. */ e=>e.key===\'Enter\'&&load(q)}', $source);
    }

    /** Game campaign mutations require games.manage while entry inspection remains a view action. */
    public function test_game_writes_require_manage_capability_but_entries_remain_readable(): void
    {
        $source = $this->gamesSource();

        $this->assertStringContainsString("const canManage=hasPermission('games.manage');", $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'if(!canManage)return;'));
        $this->assertStringContainsString('{canManage&&<Card><SectionHeader title="Create campaign"/>', $source);
        $this->assertStringContainsString('apiGet(`/admin/engagement/games/${g.id}/entries`)', $source);
        $this->assertStringContainsString('>Entries</Button>{canManage&&<>', $source);
        $this->assertStringContainsString('`/admin/games/${g.id}/close`', $source);
        $this->assertStringContainsString('`/admin/games/${g.id}/draw`', $source);
        $this->assertStringContainsString('`/admin/games/${g.id}/fulfill`', $source);
    }

    /** The capability names used by the client are canonical published admin permissions. */
    public function test_engagement_manage_permissions_are_published(): void
    {
        $permissions = config('rbac.roles.admin', []);

        $this->assertContains('loyalty.manage', $permissions);
        $this->assertContains('games.manage', $permissions);
    }
}
