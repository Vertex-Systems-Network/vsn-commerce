<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks Notifications and Settings read/write presentation to their published admin capabilities. */
class AdminNotificationSettingsCapabilityPresentationTest extends TestCase
{
    /** Reads the shared Admin Operations React module. */
    private function operationsSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/AdminOperations.jsx'));

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

    /** Returns only the Notifications surface from the shared operations module. */
    private function notificationsSource(): string
    {
        $parts = explode('/** Handles admin notifications for the VSN Ecommerce interface. */', $this->operationsSource(), 2);
        $this->assertCount(2, $parts);
        $sections = explode('/** Handles admin settings for the VSN Ecommerce interface. */', $parts[1], 2);
        $this->assertCount(2, $sections);

        return $sections[0];
    }

    /** Returns only the Settings surface from the shared operations module. */
    private function settingsSource(): string
    {
        $parts = explode('/** Handles admin settings for the VSN Ecommerce interface. */', $this->operationsSource(), 2);
        $this->assertCount(2, $parts);

        return $parts[1];
    }

    /** Notifications and Settings remain readable through their view capabilities. */
    public function test_routes_preserve_view_capabilities(): void
    {
        $source = $this->appSource();

        $this->assertStringContainsString(
            '<Route path="notifications" element={permit("notifications.view", <AdminNotifications/>)}/>',
            $source,
        );
        $this->assertStringContainsString(
            '<Route path="settings" element={permit("settings.view", <AdminSettings/>)}/>',
            $source,
        );
    }

    /** Broadcast creation and delivery retries require notifications.manage while read evidence remains visible. */
    public function test_notification_writes_require_manage_capability(): void
    {
        $source = $this->notificationsSource();

        $this->assertStringContainsString("const canManage=hasPermission('notifications.manage');", $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'if(!canManage)return;'));
        $this->assertStringContainsString('{canManage&&<Card><SectionHeader title="New broadcast"/>', $source);
        $this->assertStringContainsString("canManage&&['failed','disabled'].includes(d.status)", $source);
        $this->assertStringContainsString("apiGet('/admin/notifications/campaigns')", $source);
        $this->assertStringContainsString('title="Campaign summary"', $source);
        $this->assertStringContainsString('title="Recent broadcast recipients"', $source);
    }

    /** Settings remain readable but local edits and saves require settings.manage. */
    public function test_settings_writes_require_manage_capability(): void
    {
        $source = $this->settingsSource();

        $this->assertStringContainsString("const canManage=hasPermission('settings.manage');", $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'if(!canManage)return;'));
        $this->assertGreaterThanOrEqual(10, substr_count($source, 'disabled={!canManage}'));
        $this->assertGreaterThanOrEqual(4, substr_count($source, '{canManage&&<Button'));
        $this->assertStringContainsString("apiGet('/admin/settings')", $source);
        $this->assertStringContainsString('title="Store"', $source);
        $this->assertStringContainsString('title="Operations banner"', $source);
    }

    /** The manage permissions used by the client are canonical published admin permissions. */
    public function test_manage_permissions_are_published(): void
    {
        $permissions = config('rbac.roles.admin', []);

        $this->assertContains('notifications.manage', $permissions);
        $this->assertContains('settings.manage', $permissions);
    }
}
