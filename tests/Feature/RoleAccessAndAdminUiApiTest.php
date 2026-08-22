<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Defines the RoleAccessAndAdminUiApiTest class and its project responsibilities. */
class RoleAccessAndAdminUiApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies guest cannot read authenticated profile. */
    public function test_guest_cannot_read_authenticated_profile(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /** Verifies customer cannot access admin api. */
    public function test_customer_cannot_access_admin_api(): void
    {
        $customer = User::create(['name'=>'Customer','email'=>'customer-role@example.com','password'=>'StrongPass123','role'=>'customer']);
        $this->actingAs($customer)->getJson('/api/v1/admin/users')->assertForbidden();
    }

    /** Verifies customer cannot access vendor api. */
    public function test_customer_cannot_access_vendor_api(): void
    {
        $customer = User::create(['name'=>'Customer','email'=>'customer-vendor@example.com','password'=>'StrongPass123','role'=>'customer']);
        $this->actingAs($customer)->getJson('/api/v1/vendor/catalog')->assertForbidden();
    }

    /** Verifies super admin can create user. */
    public function test_super_admin_can_create_user(): void
    {
        $admin = User::create(['name'=>'Admin','email'=>'admin-role@example.com','password'=>'StrongPass123','role'=>'super_admin']);
        $response = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name'=>'Created User','email'=>'created@example.com','password'=>'StrongPass123','role'=>'customer','emailVerified'=>true,
        ]);
        $response->assertCreated()->assertJsonPath('data.email','created@example.com')->assertJsonPath('data.role','customer');
        $this->assertDatabaseHas('users',['email'=>'created@example.com','role'=>'customer']);
    }

    /** Verifies regular admin cannot create super admin. */
    public function test_regular_admin_cannot_create_super_admin(): void
    {
        $admin = User::create(['name'=>'Admin','email'=>'admin-plain@example.com','password'=>'StrongPass123','role'=>'admin']);
        $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name'=>'Root','email'=>'root@example.com','password'=>'StrongPass123','role'=>'super_admin','emailVerified'=>true,
        ])->assertForbidden();
    }
}
