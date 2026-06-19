<?php

namespace Softlab180\Bancard\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Softlab180\Bancard\BancardServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BancardServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('bancard.public_key', 'test_public_key');
        $app['config']->set('bancard.private_key', 'test_private_key');
        $app['config']->set('bancard.environment', 'production');
        // En tests no aplicamos throttle a las rutas de webhook.
        $app['config']->set('bancard.webhook.middleware', []);
    }
}
