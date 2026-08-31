<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks Admin Catalog read/write presentation to effective catalog capabilities. */
class AdminCatalogCapabilityPresentationTest extends TestCase
{
    /** Reads the canonical shared catalog React surface. */
    private function catalogSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/CatalogManagement.jsx'));

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

    /** Admin Catalog list remains view-only while editor routes retain manage authority. */
    public function test_admin_catalog_route_preserves_view_manage_boundary(): void
    {
        $source = $this->appSource();

        $this->assertStringContainsString(
            '<Route path="catalog" element={permit("catalog.view", <AdminCatalog/>)}/>',
            $source,
        );
        $this->assertStringContainsString(
            '<Route path="catalog/new" element={permit("catalog.manage", <AdminProductEditor/>)}/>',
            $source,
        );
        $this->assertStringContainsString(
            '<Route path="catalog/:id/edit" element={permit("catalog.manage", <AdminProductEditor/>)}/>',
            $source,
        );
    }

    /** Admin product and category write presentation must require catalog.manage. */
    public function test_admin_catalog_write_controls_require_manage_capability(): void
    {
        $source = $this->catalogSource();

        $this->assertStringContainsString("const {hasPermission}=useAuth();const canManage=hasPermission('catalog.manage');", $source);
        $this->assertStringContainsString("action={canManage?'Add product':undefined}", $source);
        $this->assertStringContainsString('{canManage&&<Link to={`/admin/catalog/${p.id}/edit`}>Edit</Link>}', $source);
        $this->assertStringContainsString("{canManage&&p.status==='pending_review'&&<Button", $source);
        $this->assertStringContainsString("{canManage&&p.status==='published'&&<Button", $source);
        $this->assertStringContainsString('<CategoryManager rows={data?.categories||[]} reload={load} canManage={canManage}/>', $source);
        $this->assertStringContainsString('{canManage&&<div style={{display:\'flex\',gap:8}}>', $source);
        $this->assertStringContainsString('{canManage&&<Button variant="secondary"', $source);
    }

    /** Admin catalog mutation handlers fail closed even if invoked outside their presentation controls. */
    public function test_admin_catalog_mutation_handlers_fail_closed_without_manage_capability(): void
    {
        $source = $this->catalogSource();

        $this->assertGreaterThanOrEqual(3, substr_count($source, 'if(!canManage)return;'));
        $this->assertStringContainsString('apiPost(`/admin/products/${id}/review`,{status:next})', $source);
        $this->assertStringContainsString("apiPost('/admin/categories',{name})", $source);
        $this->assertStringContainsString('apiPut(`/admin/categories/${c.id}`,{isActive:!c.isActive})', $source);
    }

    /** View-only administrators keep catalog state and seller catalog behavior remains unchanged. */
    public function test_catalog_read_state_and_seller_contract_are_retained(): void
    {
        $source = $this->catalogSource();

        $this->assertStringContainsString('apiGet(`/admin/catalog${status?`?status=${status}`:\'\'}`)', $source);
        $this->assertStringContainsString("['','pending_review','published','draft','suspended']", $source);
        $this->assertStringContainsString('<SectionHeader title="Products" action="Add product" to="/vendor/products/new"/>', $source);
        $this->assertStringContainsString('apiPost(`/vendor/products/${id}/submit`,{})', $source);
    }
}
