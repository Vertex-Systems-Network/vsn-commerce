<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks Production Readiness reads and mutations to their effective operations capabilities. */
class ProductionReadinessCapabilityPresentationTest extends TestCase
{
    /** Reads the Production Readiness React surface. */
    private function productionSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/Production.jsx'));

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

    /** Production Readiness remains readable with operations.view. */
    public function test_production_readiness_route_preserves_view_capability(): void
    {
        $this->assertStringContainsString(
            '<Route path="production-readiness" element={permit("operations.view", <ProductionReadiness/>)}/>',
            $this->appSource(),
        );
    }

    /** Launch-gate and provider mutations require operations.manage while read evidence remains available. */
    public function test_production_readiness_writes_require_manage_capability(): void
    {
        $source = $this->productionSource();

        $this->assertStringContainsString("const canManageOperations=hasPermission('operations.manage');", $source);
        $this->assertGreaterThanOrEqual(3, substr_count($source, 'if(!canManageOperations)return;'));
        $this->assertStringContainsString('{canManageOperations&&<Button onClick={run}', $source);
        $this->assertStringContainsString('{canManageOperations&&<Button variant="secondary" onClick={probe}', $source);
        $this->assertStringContainsString('{canManageOperations&&reconcilable.has(p.type)&&<>', $source);
        $this->assertStringContainsString("apiGet('/admin/system/launch-gate')", $source);
        $this->assertStringContainsString("apiGet('/admin/system/providers')", $source);
        $this->assertStringContainsString('<h2>Recent provider reconciliations</h2>', $source);
        $this->assertStringContainsString('<h2>Latest persisted run</h2>', $source);
    }

    /** The manage capability used by the client is a canonical published admin permission. */
    public function test_operations_manage_permission_is_published(): void
    {
        $permissions = config('rbac.roles.admin', []);

        $this->assertContains('operations.manage', $permissions);
    }
}
