<?php

namespace Tests\Feature;

use App\Providers\ProductionSafetyServiceProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Guards predictable demo identities from production seeding. */
class ProductionDemoSeedGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Production forces demo seeding off even when the demo flag is explicitly enabled. */
    public function test_production_forces_demo_seed_off_when_flag_is_enabled(): void
    {
        $this->app['env'] = 'production';
        config()->set('vsn.demo.enabled', true);

        (new ProductionSafetyServiceProvider($this->app))->boot();

        $this->assertFalse(config('vsn.demo.enabled'));

        app(DatabaseSeeder::class)->run();

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'seller@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'customer@example.test']);
    }
}
