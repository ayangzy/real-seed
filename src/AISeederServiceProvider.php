<?php

namespace AISeeder;

use AISeeder\Console\Commands\AiSeedCommand;
use Illuminate\Support\ServiceProvider;

class AISeederServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-seeder.php', 'ai-seeder');
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
