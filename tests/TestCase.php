<?php

namespace Ayangzy\RealSeed\Tests;

use Ayangzy\RealSeed\RealSeedServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RealSeedServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', self::testConnection());
        $app['config']->set('realseed.model_paths', [__DIR__.'/Fixtures/Models']);

        // Tests never reach a real AI provider; AI tests bind a fake explicitly.
        $app['config']->set('realseed.ai.enabled', false);
        $app['config']->set('realseed.plans_path', sys_get_temp_dir().'/realseed-tests/'.uniqid());
    }

    /**
     * SQLite in memory by default; set REALSEED_TEST_DRIVER=mysql|pgsql (plus DB_HOST, DB_PORT,
     * DB_DATABASE, DB_USERNAME, DB_PASSWORD) to run the suite against a real server.
     */
    private static function testConnection(): array
    {
        $driver = getenv('REALSEED_TEST_DRIVER') ?: 'sqlite';

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }

        return [
            'driver' => $driver,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('DB_DATABASE') ?: 'realseed_test',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'search_path' => 'public',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Server databases persist between tests, unlike SQLite in memory. The connection is
        // purged afterwards so tests that change its configuration get a fresh one.
        if ($this->app['config']->get('database.connections.testbench.driver') !== 'sqlite') {
            $this->app['db']->connection('testbench')->getSchemaBuilder()->dropAllTables();
            $this->app['db']->purge('testbench');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->app['config']->get('realseed.plans_path'));
        }

        parent::tearDown();
    }

    /**
     * Registries are singletons built from config; rebuild them after changing config in a test.
     */
    protected function refreshApplicationBindings(): void
    {
        foreach ([\Ayangzy\RealSeed\Extension\ExtensionRegistry::class, \Ayangzy\RealSeed\Locale\LocaleRegistry::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    protected function setEnvironment(mixed $environment): void
    {
        $this->app['env'] = $environment;
    }
}
