<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks Admin Users read/write presentation to effective users capabilities. */
class AdminUserCapabilityPresentationTest extends TestCase
{
    /** Reads the canonical Admin Users React surface. */
    private function userSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/AdminUsers.jsx'));

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

    /** Users view remains a read route and does not require manage authority at entry. */
    public function test_users_route_remains_view_capability(): void
    {
        $this->assertStringContainsString(
            '<Route path="users" element={permit("users.view", <AdminUsers/>)}/>',
            $this->appSource(),
        );
    }

    /** View-only principals must not receive create-user presentation or an executable create handler. */
    public function test_create_user_requires_users_manage(): void
    {
        $source = $this->userSource();

        $this->assertStringContainsString("const canManage=hasPermission('users.manage');", $source);
        $this->assertStringContainsString('{canManage&&<Card><SectionHeader title="Create user"', $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'if(!canManage)return;'));
    }

    /** Role mutation presentation is manage-only while view-only users retain readable role state. */
    public function test_role_change_requires_users_manage(): void
    {
        $source = $this->userSource();

        $this->assertStringContainsString('{canManage?<select aria-label={`Role for ${u.name}`}', $source);
        $this->assertStringContainsString(":u.role.replaceAll('_',' ')}</td>", $source);
        $this->assertStringContainsString("apiPut(`/admin/users/${user.id}`,{role:next})", $source);
    }
}
