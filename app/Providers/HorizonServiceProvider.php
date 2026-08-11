<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Configure the Horizon authorization services.
     *
     * Horizon is available in the local environment only.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function ($request) {
            return app()->environment('local');
        });
    }

    /**
     * Register the Horizon gate (unused outside local; kept for Horizon base class).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null): bool {
            return false;
        });
    }
}
