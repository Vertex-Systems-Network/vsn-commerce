<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use RuntimeException;
use Tests\TestCase;

/** Guards predictable demo identities from production seeding. */
class ProductionDemoSeedGuardTest extends TestCase
{
    /** Production must reject demo seeding even when the demo flag is explicitly enabled. */
    public function test_production_rejects_demo_seed_when_flag_is_enabled(): void
    {
        $this->app['env'] = 'production';
        config()->set('vsn.demo.enabled', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Refusing to run VSN demo seed in production while VSN_DEMO_SEED_ENABLED is enabled.'
        );

        (new DatabaseSeeder())->run();
    }
}
