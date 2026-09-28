<?php

namespace AISeeder;

use AISeeder\AI\AIProviderInterface;
use AISeeder\AI\Providers\LaravelAiProvider;
use AISeeder\AI\Providers\NullProvider;
use AISeeder\Console\Commands\AiSeedCommand;
use AISeeder\Planning\PlanStore;
use Illuminate\Support\ServiceProvider;

class AISeederServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-seeder.php', 'ai-seeder');

        $this->app->singleton(AIProviderInterface::class, function ($app) {
            $config = (array) $app['config']->get('ai-seeder.ai', []);
            $driver = $config['driver'] ?? 'laravel-ai';

            return match (true) {
                $driver === 'laravel-ai' => new LaravelAiProvider($config['provider'] ?? null, $config['model'] ?? null, (int) ($config['timeout'] ?? 120)),
                is_string($driver) && is_subclass_of($driver, AIProviderInterface::class) => $app->make($driver),
                default => new NullProvider('Unknown AI driver ['.(is_string($driver) ? $driver : get_debug_type($driver)).'].'),
            };
        });

        $this->app->singleton(PlanStore::class, fn ($app) => new PlanStore(
            $app['files'],
            $app['config']->get('ai-seeder.plans_path') ?? $app->storagePath('ai-seeder/plans'),
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/ai-seeder.php' => config_path('ai-seeder.php'),
        ], 'ai-seeder-config');

        $this->commands([AiSeedCommand::class]);
    }
}
