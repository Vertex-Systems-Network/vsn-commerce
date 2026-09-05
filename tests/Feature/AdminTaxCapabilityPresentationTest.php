<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks view/manage presentation boundaries for Admin Tax. */
class AdminTaxCapabilityPresentationTest extends TestCase
{
    /** Reads the Tax React module. */
    private function taxSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/Tax.jsx'));

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

    /** Admin Tax remains a view-capability route. */
    public function test_admin_tax_route_remains_tax_view_capability(): void
    {
        $this->assertStringContainsString(
            '<Route path="tax" element={permit("tax.view", <AdminTax/>)}/>',
            $this->appSource(),
        );
    }

    /** Consequential tax writes are hidden and fail closed without tax.manage. */
    public function test_admin_tax_mutations_use_tax_manage(): void
    {
        $source = $this->taxSource();

        $this->assertStringContainsString('import {useAuth} from "../platform/auth";', $source);
        $this->assertStringContainsString("const canManageTax=hasPermission('tax.manage');", $source);
        $this->assertStringContainsString('async(fn)=>{if(!canManageTax)return;', $source);
        $this->assertStringContainsString('{canManageTax&&<div className="dashboard-grid">', $source);
        $this->assertStringContainsString('{canManageTax&&<Button variant="secondary"', $source);
        $this->assertStringContainsString('{canManageTax&&<Button onClick=', $source);
        $this->assertStringContainsString("apiPost('/admin/tax/jurisdictions',j)", $source);
        $this->assertStringContainsString("apiPost('/admin/tax/classes',c)", $source);
        $this->assertStringContainsString("apiPost('/admin/tax/rates'", $source);
        $this->assertStringContainsString('apiPut(`/admin/tax/rates/${y.id}`', $source);
        $this->assertStringContainsString('apiPost(`/admin/tax/vendor-profiles/${p.id}/review`', $source);
    }

    /** Read-only tax evidence stays visible independently of manage permission. */
    public function test_admin_tax_read_evidence_is_not_manage_gated(): void
    {
        $source = $this->taxSource();

        $this->assertStringContainsString('<Card><h2>Configured jurisdictions</h2>', $source);
        $this->assertStringContainsString('<Card><h2>Seller tax registrations</h2>', $source);
        $this->assertStringNotContainsString('{canManageTax&&<Card><h2>Configured jurisdictions</h2>', $source);
        $this->assertStringNotContainsString('{canManageTax&&<Card><h2>Seller tax registrations</h2>', $source);
    }

    /** Published roles preserve the explicit tax view/manage capability pair. */
    public function test_published_tax_capabilities_include_view_and_manage(): void
    {
        foreach (['finance', 'admin'] as $role) {
            $permissions = config("rbac.roles.{$role}", []);
            $this->assertContains('tax.view', $permissions);
            $this->assertContains('tax.manage', $permissions);
        }
    }
}
