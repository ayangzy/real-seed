<?php

use Ayangzy\RealSeed\Analysis\FactoryAnalyzer;
use Ayangzy\RealSeed\Tests\Fixtures\Factories\UserFactory;
use Ayangzy\RealSeed\Tests\Fixtures\SaasSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-15 12:00:00');
    SaasSchema::create();
    $this->setEnvironment('local');
});

function seedWith(string $strategy, array $options = []): int
{
    return Artisan::call('realseed', ['--seed' => 5, '--size' => 'small', '--no-interaction' => true, '--strategy' => $strategy, ...$options]);
}

it('discovers factories and their states', function () {
    $factories = (new FactoryAnalyzer)->analyze(analyzeSaasFixture()->models);

    expect(array_keys($factories))->toBe(['organizations', 'projects', 'users'])
        ->and($factories['users']->factory)->toBe(UserFactory::class)
        ->and($factories['users']->states)->toBe(['suspended']);
});

it('uses factory values in factory mode while RealSeed owns keys and relationships', function () {
    expect(seedWith('factory'))->toBe(0);

    $output = Artisan::output();
    $users = DB::table('users')->get();

    expect($output)->toContain('Strategy:                factory (factories for')
        ->and($users->pluck('last_name')->unique()->all())->toBe(['Factoryson'])
        ->and($users->pluck('first_name')->unique()->diff(['Ada', 'Grace', 'Katherine', 'Hedy']))->toBeEmpty()
        ->and($users->pluck('status')->unique()->all())->toBe(['invited'])
        // Nested factories were overridden: no extra organizations were created.
        ->and(DB::table('organizations')->count())->toBe(3)
        ->and(DB::table('organizations')->pluck('name')->every(fn ($n) => preg_match('/^(Northwind|Contoso|Fabrikam) \d+$/', $n)))->toBeTrue()
        ->and(DB::table('organizations')->whereNull('owner_id')->count())->toBe(0)
        // Timestamps the factory doesn't define still come from the engine.
        ->and(DB::table('users')->whereNull('created_at')->count())->toBe(0);
});

it('lets the engine own distributions and timelines in hybrid mode', function () {
    seedWith('hybrid');
    $users = DB::table('users')->get();

    expect($users->pluck('last_name')->unique()->all())->toBe(['Factoryson'])
        ->and($users->pluck('status')->unique()->count())->toBeGreaterThan(1)
        ->and($users->pluck('status'))->toContain('active');
});

it('detects a factory that writes to the database, rolls it back and falls back', function () {
    expect(seedWith('factory'))->toBe(0);

    expect(Artisan::output())->toContain('ProjectFactory was not used for [projects]: its definition writes to the database')
        ->and(DB::table('tags')->where('name', 'like', 'side-effect-%')->count())->toBe(0)
        ->and(DB::table('projects')->where('name', 'Unsafe project')->count())->toBe(0)
        ->and(DB::table('projects')->count())->toBeGreaterThan(0);
});

it('applies factory states suggested by the AI', function () {
    config(['realseed.ai.enabled' => true]);
    app()->instance(\Ayangzy\RealSeed\AI\AIProviderInterface::class, new \Ayangzy\RealSeed\Tests\Fixtures\FakeAIProvider([
        'domain' => 'x', 'timeline_months' => null,
        'tables' => [['table' => 'users', 'count' => null, 'fields' => [], 'states' => [['name' => 'suspended', 'weight' => 1], ['name' => 'rm -rf', 'weight' => 5]]]],
    ]));

    seedWith('factory');

    expect(DB::table('users')->pluck('status')->unique()->all())->toBe(['suspended']);
});

it('is reproducible with factories', function () {
    seedWith('hybrid');
    $first = DB::table('users')->orderBy('id')->get(['first_name', 'email', 'status', 'created_at'])->all();

    Schema::dropAllTables();
    SaasSchema::create();
    seedWith('hybrid');

    expect(DB::table('users')->orderBy('id')->get(['first_name', 'email', 'status', 'created_at'])->all())->toEqual($first);
});

it('ignores factories with the default strategy and rejects unknown strategies', function () {
    seedWith('ai');

    expect(DB::table('users')->where('last_name', 'Factoryson')->count())->toBe(0);

    expect(seedWith('magic'))->toBe(1)
        ->and(Artisan::output())->toContain('--strategy must be ai, factory, or hybrid');
});
