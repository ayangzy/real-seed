<?php

use Illuminate\Support\Facades\DB;

function countQueries(callable $callback): int
{
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $callback();

    return $queries;
}

it('rejects unsupported environments without touching the database', function (string $environment) {
    $this->setEnvironment($environment);

    $queries = countQueries(function () use ($environment) {
        $this->artisan('ai:seed')
            ->expectsOutputToContain("Environment: {$environment}")
            ->expectsOutputToContain('✗ AI Seeder cannot run in this environment.')
            ->expectsOutputToContain('No database changes were made.')
            ->doesntExpectOutputToContain('Continue')
            ->assertExitCode(1);
    });

    expect($queries)->toBe(0);
})->with(['production', 'prod', 'testing', 'qa', 'uat', 'unknown']);

it('proceeds in supported environments and shows the target', function () {
    $this->setEnvironment('local');

    $this->artisan('ai:seed')
        ->expectsOutputToContain('Environment: local')
        ->expectsOutputToContain('Connection: testbench ('.config('database.connections.testbench.driver').')')
        ->expectsOutputToContain('✓ Environment supported')
        ->expectsOutputToContain('✓ Production protection active')
        ->assertExitCode(0);
});

it('blocks a supported environment pointed at a production-looking database', function () {
    $this->setEnvironment('staging');
    config(['database.connections.testbench.database' => 'myapp_production']);

    $this->artisan('ai:seed')
        ->expectsOutputToContain('looks like a production database')
        ->expectsOutputToContain('No database changes were made.')
        ->assertExitCode(1);
});

it('requires the database name to be typed when local points at a remote host', function () {
    $this->setEnvironment('local');
    config(['database.connections.testbench' => [
        'driver' => 'mysql', 'host' => 'db.example.com', 'database' => 'myapp',
    ]]);

    $this->artisan('ai:seed')
        ->expectsQuestion('Type the database name [myapp] to continue', 'wrong')
        ->expectsOutputToContain('did not match')
        ->assertExitCode(1);

    $this->artisan('ai:seed')
        ->expectsQuestion('Type the database name [myapp] to continue', 'myapp')
        ->expectsOutputToContain('✓ Environment supported')
        ->expectsOutputToContain('Could not read the database schema')
        ->assertExitCode(1);
});

it('refuses remote-host confirmation in non-interactive mode', function () {
    $this->setEnvironment('local');
    config(['database.connections.testbench' => [
        'driver' => 'mysql', 'host' => 'db.example.com', 'database' => 'myapp',
    ]]);

    $this->artisan('ai:seed', ['--no-interaction' => true])
        ->expectsOutputToContain('non-interactive')
        ->assertExitCode(1);
});

it('offers no environment bypass options', function (string $option) {
    $definition = $this->app->make(\AISeeder\Console\Commands\AiSeedCommand::class)->getDefinition();

    expect($definition->hasOption($option))->toBeFalse();
})->with(['force', 'allow-production', 'ignore-environment', 'skip-environment-check', 'unsafe']);

it('summarises the analysed application', function () {
    \AISeeder\Tests\Fixtures\SaasSchema::create();
    $this->setEnvironment('local');

    $this->artisan('ai:seed', ['--dry-run' => true])
        ->expectsOutputToContain('6 models detected')
        ->expectsOutputToContain('8 tables detected')
        ->expectsOutputToContain('2 enums detected')
        ->expectsOutputToContain('Detected application structures:')
        ->expectsOutputToContain('Organizations')
        ->assertExitCode(0);
});

it('stops when migrations are pending', function () {
    $this->setEnvironment('local');
    $this->app['migrator']->path(__DIR__.'/../Fixtures/migrations');

    $this->artisan('ai:seed')
        ->expectsOutputToContain('1 of 1 migrations have not been run')
        ->expectsOutputToContain('No database changes were made.')
        ->assertExitCode(1);
});
