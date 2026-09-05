<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks view/manage presentation boundaries for Admin Risk. */
class AdminRiskCapabilityPresentationTest extends TestCase
{
    /** Reads the Risk React module. */
    private function riskSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/Risk.jsx'));

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

    /** Admin Risk remains a view-capability route. */
    public function test_admin_risk_route_remains_risk_view_capability(): void
    {
        $this->assertStringContainsString(
            '<Route path="risk" element={permit("risk.view", <AdminRisk/>)}/>',
            $this->appSource(),
        );
    }

    /** Consequential risk writes are hidden and fail closed without risk.manage. */
    public function test_admin_risk_mutations_use_risk_manage(): void
    {
        $source = $this->riskSource();

        $this->assertStringContainsString('import {useAuth} from "../platform/auth";', $source);
        $this->assertStringContainsString("const canManageRisk=hasPermission('risk.manage');", $source);
        $this->assertStringContainsString('async(fn)=>{if(!canManageRisk)return;', $source);
        $this->assertStringContainsString('{canManageRisk&&<><div className="form-grid">', $source);
        $this->assertStringContainsString('{canManageRisk&&p.user&&<Button', $source);
        $this->assertStringContainsString('{canManageRisk&&p.vendor&&<Button', $source);
        $this->assertStringContainsString('{canManageRisk&&<div><Button variant="secondary"', $source);
        $this->assertStringContainsString('{canManageRisk&&<Button variant="secondary"', $source);
        $this->assertStringContainsString("apiPost('/admin/risk/holds'", $source);
        $this->assertStringContainsString('/evaluate', $source);
        $this->assertStringContainsString('/status', $source);
        $this->assertStringContainsString('/release', $source);
    }

    /** Read-only risk evidence stays visible independently of manage permission. */
    public function test_admin_risk_read_evidence_is_not_manage_gated(): void
    {
        $source = $this->riskSource();

        foreach (['Highest risk profiles', 'Open cases', 'Active holds', 'Recent immutable evidence'] as $heading) {
            $this->assertStringContainsString("<h2>{$heading}</h2>", $source);
        }
    }

    /** Published Admin capabilities preserve the explicit risk view/manage pair. */
    public function test_published_admin_risk_capabilities_include_view_and_manage(): void
    {
        $permissions = config('rbac.roles.admin', []);

        $this->assertContains('risk.view', $permissions);
        $this->assertContains('risk.manage', $permissions);
    }
}
