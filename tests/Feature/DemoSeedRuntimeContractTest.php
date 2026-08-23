<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Verifies browser-critical API paths against the full demo environment seed. */
class DemoSeedRuntimeContractTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies seeded public, seller and admin catalog paths do not trigger lazy-loading failures. */
    public function test_demo_seed_browser_critical_endpoints_are_runtime_safe(): void
    {
        config(['vsn.demo.enabled' => true]);
        $this->seed(DatabaseSeeder::class);

        $this->getJson('/api/v1/products?sort=popular&perPage=12')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'meta', 'facets']]);

        $this->getJson('/api/v1/recommendations?limit=8')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'personalized']]);

        $seller = User::query()->where('email', 'seller@example.test')->firstOrFail();
        Sanctum::actingAs($seller);
        $this->getJson('/api/v1/vendor/overview')->assertOk();
        $this->getJson('/api/v1/vendor/catalog')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'meta', 'categories']]);

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/catalog')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'meta', 'categories', 'vendors']]);
    }
}
