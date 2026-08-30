<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks Admin Vendors presentation and secondary data loading to effective capabilities. */
class AdminVendorCapabilityCompositionTest extends TestCase
{
    /** Reads the canonical Admin Vendors React surface. */
    private function vendorSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/AdminVendors.jsx'));

        $this->assertIsString($source);

        return $source;
    }

    /** Vendor read access must not force the separately protected seller-owner user lookup. */
    public function test_seller_owner_lookup_requires_manage_and_users_view(): void
    {
        $source = $this->vendorSource();

        $this->assertStringContainsString("const canManage=hasPermission('vendors.manage'),canReadOwners=hasPermission('users.view'),canCreate=canManage&&canReadOwners;", $source);
        $this->assertStringContainsString("canCreate?apiGet('/admin/users?role=seller&perPage=100'):Promise.resolve({items:[]})", $source);
        $this->assertStringNotContainsString("apiGet('/admin/vendors'),apiGet('/admin/users?role=seller&perPage=100')", $source);
    }

    /** Create-vendor presentation must fail closed unless both write and owner-read capabilities exist. */
    public function test_create_vendor_controls_require_composed_capabilities(): void
    {
        $source = $this->vendorSource();

        $this->assertStringContainsString('{canCreate&&<Card><SectionHeader title="Create vendor"/>', $source);
        $this->assertStringContainsString("if(!canCreate)return;", $source);
    }

    /** Vendor status mutation presentation must require vendors.manage while read-only status remains visible. */
    public function test_status_mutation_requires_vendor_manage(): void
    {
        $source = $this->vendorSource();

        $this->assertStringContainsString("if(!canManage)return;", $source);
        $this->assertStringContainsString('{canManage?<select aria-label={`Marketplace status for ${v.name}`}', $source);
        $this->assertStringContainsString(':v.status}</td>', $source);
    }
}
