<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/** Enforces production-only safety overrides before application work is executed. */
class ProductionSafetyServiceProvider extends ServiceProvider
{
    /** Applies fail-closed production configuration. */
    public function boot(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        config()->set('vsn.demo.enabled', false);
    }
}
