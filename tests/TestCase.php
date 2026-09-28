<?php

namespace AISeeder\Tests;

use AISeeder\AISeederServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AISeederServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('ai-seeder.model_paths', [__DIR__.'/Fixtures/Models']);
    }

    protected function setEnvironment(mixed $environment): void
    {
        $this->app['env'] = $environment;
    }
}
