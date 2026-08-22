<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Defines the UnifiedApplicationTest class and its project responsibilities. */
class UnifiedApplicationTest extends TestCase
{
    /** Verifies laravel serves the react application shell. */
    public function test_laravel_serves_the_react_application_shell(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('<div id="root"></div>', false);
    }

    /** Verifies react client routes fall back to the same laravel shell. */
    public function test_react_client_routes_fall_back_to_the_same_laravel_shell(): void
    {
        $this->withoutVite();

        $this->get('/admin/catalog')
            ->assertOk()
            ->assertSee('<div id="root"></div>', false);
    }

    /** Verifies api routes are not captured by the spa fallback. */
    public function test_api_routes_are_not_captured_by_the_spa_fallback(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
    }
}
