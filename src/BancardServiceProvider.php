<?php

namespace Softlab180\Bancard;

use Illuminate\Support\ServiceProvider;
use Softlab180\Bancard\Contracts\BancardIdempotencyStore;
use Softlab180\Bancard\Idempotency\EloquentIdempotencyStore;
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

        // Store de idempotencia del webhook (dedup + alias_token del charge), inyectable
        // vía bancard.idempotency_store. Default: Eloquent (bancard_processed_callbacks).
        $this->app->singleton(BancardIdempotencyStore::class, function ($app) {
            $store = config('bancard.idempotency_store');

            if (is_string($store) && $store !== '') {
                return $app->make($store);
            }

            return new EloquentIdempotencyStore();
        });
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
