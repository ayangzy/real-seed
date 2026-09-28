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
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('ai-seeder.model_paths', [__DIR__.'/Fixtures/Models']);

        // Tests never reach a real AI provider; AI tests bind a fake explicitly.
        $app['config']->set('ai-seeder.ai.enabled', false);
        $app['config']->set('ai-seeder.plans_path', sys_get_temp_dir().'/ai-seeder-tests/'.uniqid());
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->app['config']->get('ai-seeder.plans_path'));
        }

        parent::tearDown();
    }

    /**
     * Registries are singletons built from config; rebuild them after changing config in a test.
     */
    protected function refreshApplicationBindings(): void
    {
        foreach ([\AISeeder\Extension\ExtensionRegistry::class, \AISeeder\Locale\LocaleRegistry::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    protected function setEnvironment(mixed $environment): void
    {
        $this->app['env'] = $environment;
    }
}
