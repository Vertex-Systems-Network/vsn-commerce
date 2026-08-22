<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Defines the DemoAccessIsolationTest class and its project responsibilities. */
class DemoAccessIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies demo accounts endpoint lists all primary local roles when enabled. */
    public function test_demo_accounts_endpoint_lists_all_primary_local_roles_when_enabled(): void
    {
        config(['vsn.demo.enabled' => true]);

        $response = $this->getJson('/api/v1/demo/accounts')->assertOk();
        $accounts = collect($response->json('data'));

        $this->assertCount(7, $accounts);
        $this->assertSame('ChangeMe12345', $accounts->firstWhere('email', 'customer@example.test')['password']);
        $this->assertSame('/vendor', $accounts->firstWhere('email', 'seller@example.test')['landing']);
        $this->assertSame('Super Admin', $accounts->firstWhere('email', 'admin@example.test')['role']);
    }

    /** Verifies demo accounts endpoint is not exposed when demo mode is disabled. */
    public function test_demo_accounts_endpoint_is_not_exposed_when_demo_mode_is_disabled(): void
    {
        config(['vsn.demo.enabled' => false]);

        $this->getJson('/api/v1/demo/accounts')->assertNotFound();
    }

    /** Verifies database seeder does not create predictable demo users when demo mode is disabled. */
    public function test_database_seeder_does_not_create_predictable_demo_users_when_demo_mode_is_disabled(): void
    {
        config(['vsn.demo.enabled' => false]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }
}
