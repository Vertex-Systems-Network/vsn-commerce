<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/** Enforces fail-closed safety overrides before application work is executed. */
class ProductionSafetyServiceProvider extends ServiceProvider
{
    /** Applies safety overrides outside explicitly isolated demo/test environments. */
    public function boot(): void
    {
        if ($this->app->environment(['local', 'testing', 'e2e'])) {
            return;
        }

        config()->set('vsn.demo.enabled', false);
    }
}
