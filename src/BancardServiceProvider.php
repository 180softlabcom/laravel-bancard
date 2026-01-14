<?php

namespace Softlab180\Bancard;

use Illuminate\Support\ServiceProvider;
use Softlab180\Bancard\Services\BancardVPOSService;

class BancardServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/bancard.php',
            'bancard'
        );

        $this->app->singleton(BancardVPOSService::class, function ($app) {
            return new BancardVPOSService(
                config('bancard.public_key'),
                config('bancard.private_key'),
                config('bancard.environment', 'staging')
            );
        });

        $this->app->alias(BancardVPOSService::class, 'bancard');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/bancard.php' => config_path('bancard.php'),
        ], 'bancard-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'bancard-migrations');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhooks.php');
    }
}
