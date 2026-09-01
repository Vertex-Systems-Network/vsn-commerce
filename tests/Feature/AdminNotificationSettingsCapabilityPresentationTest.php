<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks Admin Notifications and Settings read/write presentation to their effective capabilities. */
class AdminNotificationSettingsCapabilityPresentationTest extends TestCase
{
    /** Reads the shared Admin Operations React surface. */
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

    /** Returns only the notifications surface from the shared operations module. */
    private function notificationsSource(): string
    {
        $parts = explode('/** Handles admin notifications for the VSN Ecommerce interface. */', $this->operationsSource(), 2);
        $this->assertCount(2, $parts);
        $sections = explode('/** Handles admin settings for the VSN Ecommerce interface. */', $parts[1], 2);
        $this->assertCount(2, $sections);

        return $sections[0];
    }

    /** Returns only the settings surface from the shared operations module. */
    private function settingsSource(): string
    {
        $parts = explode('/** Handles admin settings for the VSN Ecommerce interface. */', $this->operationsSource(), 2);
        $this->assertCount(2, $parts);

        return $parts[1];
    }

    /** Notifications and settings remain readable with their view capabilities. */
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

    /** Broadcast and retry writes require notifications.manage while read evidence remains available. */
    public function test_notification_writes_require_manage_capability(): void
    {
        $source = $this->notificationsSource();

        $this->assertStringContainsString("const canManageNotifications=hasPermission('notifications.manage');", $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'if(!canManageNotifications)return;'));
        $this->assertStringContainsString('{canManageNotifications&&<Card><SectionHeader title="New broadcast"/>', $source);
        $this->assertStringContainsString("{canManageNotifications&&['failed','disabled'].includes(d.status)&&<Button", $source);
        $this->assertStringContainsString('<SectionHeader title="Campaign summary"/>', $source);
        $this->assertStringContainsString('<SectionHeader title="Recent broadcast recipients"/>', $source);
    }

    /** Settings edits and saves require settings.manage while view-only principals retain values. */
    public function test_settings_writes_require_manage_capability(): void
    {
        $source = $this->settingsSource();

        $this->assertStringContainsString("const canManageSettings=hasPermission('settings.manage');", $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'if(!canManageSettings)return;'));
        $this->assertGreaterThanOrEqual(8, substr_count($source, 'disabled={!canManageSettings}'));
        $this->assertGreaterThanOrEqual(4, substr_count($source, '{canManageSettings&&<Button'));
        $this->assertStringContainsString('groups.store?.storeName', $source);
        $this->assertStringContainsString('groups.orders?.orderingEnabled', $source);
        $this->assertStringContainsString('groups.catalog?.lowStockThreshold', $source);
        $this->assertStringContainsString('groups.operations?.maintenanceBanner', $source);
    }

    /** The capability names used by the client are canonical published admin permissions. */
    public function test_manage_permissions_are_published(): void
    {
        $permissions = config('rbac.roles.admin', []);

        $this->assertContains('notifications.manage', $permissions);
        $this->assertContains('settings.manage', $permissions);
    }
}
