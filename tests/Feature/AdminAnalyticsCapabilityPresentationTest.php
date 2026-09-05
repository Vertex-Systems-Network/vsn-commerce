<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks view/manage presentation boundaries for Admin Analytics. */
class AdminAnalyticsCapabilityPresentationTest extends TestCase
{
    /** Reads the Admin Analytics React module. */
    private function analyticsSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/AdminAnalytics.jsx'));

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

    /** Admin Analytics remains a view-capability route. */
    public function test_admin_analytics_route_remains_analytics_view_capability(): void
    {
        $this->assertStringContainsString(
            '<Route path="analytics" element={permit("analytics.view", <AdminAnalytics/>)}/>',
            $this->appSource(),
        );
    }

    /** Consequential report mutations are hidden and fail closed without analytics.manage. */
    public function test_admin_analytics_mutations_use_analytics_manage(): void
    {
        $source = $this->analyticsSource();

        $this->assertStringContainsString('import {useAuth} from "../platform/auth";', $source);
        $this->assertStringContainsString("const canManageAnalytics=hasPermission('analytics.manage');", $source);
        $this->assertStringContainsString('async fn=>{if(!canManageAnalytics)return;', $source);
        $this->assertStringContainsString('{canManageAnalytics&&<div className="form-row">', $source);
        $this->assertStringContainsString('{canManageAnalytics&&<><div className="form-grid">', $source);
        $this->assertStringContainsString('{canManageAnalytics&&<span><Button variant="secondary"', $source);
        $this->assertStringContainsString("apiPost('/admin/analytics/exports'", $source);
        $this->assertStringContainsString("apiPost('/admin/analytics/schedules'", $source);
        $this->assertStringContainsString('apiPut(`/admin/analytics/schedules/${s.id}`', $source);
        $this->assertStringContainsString('apiDelete(`/admin/analytics/schedules/${s.id}`', $source);
    }

    /** Read-only reporting evidence and existing downloads stay visible without manage permission. */
    public function test_admin_analytics_read_evidence_is_not_manage_gated(): void
    {
        $source = $this->analyticsSource();

        foreach (['Private CSV exports', 'Scheduled reports', 'Daily trend', 'Customer cohorts'] as $heading) {
            $this->assertStringContainsString("<h2>{$heading}</h2>", $source);
        }

        $this->assertStringContainsString('href={apiUrl(e.downloadUrl.replace', $source);
    }

    /** Published Admin and Finance capabilities preserve analytics view/manage pairs. */
    public function test_published_analytics_capabilities_include_view_and_manage(): void
    {
        foreach (['finance', 'admin'] as $role) {
            $permissions = config("rbac.roles.{$role}", []);
            $this->assertContains('analytics.view', $permissions);
            $this->assertContains('analytics.manage', $permissions);
        }
    }
}
