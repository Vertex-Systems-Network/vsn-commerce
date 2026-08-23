<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Security\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Defines the RbacDemoEnvironmentTest class and its project responsibilities. */
class RbacDemoEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies role permission matrix matches operational boundaries. */
    public function test_role_permission_matrix_matches_operational_boundaries(): void
    {
        $this->assertContains('orders.view', Rbac::permissionsForRole(UserRole::Support));
        $this->assertNotContains('orders.manage', Rbac::permissionsForRole(UserRole::Support));
        $this->assertContains('finance.manage', Rbac::permissionsForRole(UserRole::Finance));
        $this->assertNotContains('settings.manage', Rbac::permissionsForRole(UserRole::Finance));
        $this->assertContains('reviews.moderate', Rbac::permissionsForRole(UserRole::Moderator));
        $this->assertNotContains('migration.manage', Rbac::permissionsForRole(UserRole::Admin));
        $this->assertContains('*', Rbac::permissionsForRole(UserRole::SuperAdmin));
    }

    /** Verifies every declared admin and vendor route has rbac mapping. */
    public function test_every_declared_admin_and_vendor_route_has_rbac_mapping(): void
    {
        $source = file_get_contents(base_path('routes/api.php'));
        preg_match_all("/Route::(get|post|put|patch|delete)\\('\/(admin|vendor)\/([^']+)'/", $source, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches);

        foreach ($matches as $match) {
            $method = strtoupper($match[1]);
            $uri = '/api/v1/'.$match[2].'/'.preg_replace('/\\{[^}]+\\}/', 'demo', $match[3]);
            $request = Request::create($uri, $method);
            $this->assertNotNull(Rbac::requiredForAreaRequest($request), "Missing RBAC mapping for {$method} {$uri}");
        }
    }

    /** Verifies user resource permissions are effective not frontend role guesses. */
    public function test_user_resource_permissions_are_effective_not_frontend_role_guesses(): void
    {
        $support = new User(['name' => 'Support', 'email' => 'support@example.test', 'role' => UserRole::Support]);
        $support->id = 101;
        $resource = (new UserResource($support))->resolve(Request::create('/api/v1/auth/me'));
        $this->assertContains('orders.view', $resource['permissions']);
        $this->assertNotContains('orders.manage', $resource['permissions']);
    }

    /** Verifies seller staff does not inherit owner permissions without membership model. */
    public function test_seller_staff_does_not_inherit_owner_permissions_without_membership_model(): void
    {
        $permissions = Rbac::permissionsForRole(UserRole::SellerStaff);
        $this->assertContains('account.access', $permissions);
        $this->assertNotContains('seller.catalog.manage', $permissions);
        $this->assertNotContains('seller.payouts.manage', $permissions);
    }

    /** Verifies demo accounts endpoint is disabled when demo mode is off. */
    public function test_demo_accounts_endpoint_is_disabled_when_demo_mode_is_off(): void
    {
        config(['vsn.demo.enabled' => false]);
        $this->getJson('/api/v1/demo/accounts')->assertNotFound();
    }
}
