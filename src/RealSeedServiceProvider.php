<?php

namespace Ayangzy\RealSeed;

use Ayangzy\RealSeed\AI\AIProviderInterface;
use Ayangzy\RealSeed\AI\Providers\LaravelAiProvider;
use Ayangzy\RealSeed\AI\Providers\NullProvider;
use Ayangzy\RealSeed\Console\Commands\RealSeedCommand;
use Ayangzy\RealSeed\Extension\ExtensionRegistry;
use Ayangzy\RealSeed\Locale\LocaleRegistry;
use Ayangzy\RealSeed\Planning\PlanStore;
use Illuminate\Support\ServiceProvider;

class RealSeedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/realseed.php', 'realseed');

        $this->app->singleton(AIProviderInterface::class, function ($app) {
            $config = (array) $app['config']->get('realseed.ai', []);
            $driver = $config['driver'] ?? 'laravel-ai';

            return match (true) {
                $driver === 'laravel-ai' => new LaravelAiProvider($config['provider'] ?? null, $config['model'] ?? null, (int) ($config['timeout'] ?? 300)),
                is_string($driver) && is_subclass_of($driver, AIProviderInterface::class) => $app->make($driver),
                default => new NullProvider('Unknown AI driver ['.(is_string($driver) ? $driver : get_debug_type($driver)).'].'),
            };
        });

        $this->app->singleton(ExtensionRegistry::class, fn ($app) => new ExtensionRegistry($app, [
            'generators' => (array) $app['config']->get('realseed.generators', []),
            'row_generators' => (array) $app['config']->get('realseed.row_generators', []),
            'reference_pickers' => (array) $app['config']->get('realseed.reference_pickers', []),
            'scenarios' => (array) $app['config']->get('realseed.scenarios', []),
            'analyzers' => (array) $app['config']->get('realseed.analyzers', []),
        ]));

        $this->app->singleton(LocaleRegistry::class, fn ($app) => new LocaleRegistry(
            $app,
            (array) $app['config']->get('realseed.locales', []),
            $app['config']->get('realseed.currency'),
        ));

        $this->app->singleton(PlanStore::class, fn ($app) => new PlanStore(
            $app['files'],
            $app['config']->get('realseed.plans_path') ?? $app->storagePath('realseed/plans'),
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/realseed.php' => config_path('realseed.php'),
        ], 'realseed-config');

        $this->commands([RealSeedCommand::class]);
    }
}
