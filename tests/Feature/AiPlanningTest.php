<?php

use AISeeder\AI\AIProviderInterface;
use AISeeder\Tests\Fixtures\FakeAIProvider;
use AISeeder\Tests\Fixtures\SaasSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const TASK_TITLES = ['Draft Q3 onboarding checklist', 'Fix invoice PDF rounding', 'Review vendor contract renewal', 'Migrate billing webhooks'];

function suggestions(array $overrides = []): array
{
    return array_replace([
        'domain' => 'A project management tool for agencies.',
        'timeline_months' => 3,
        'tables' => [
            ['table' => 'organizations', 'count' => 2, 'fields' => []],
            ['table' => 'tasks', 'count' => 40, 'fields' => [
                field('title', samples: TASK_TITLES),
                field('status', weights: [['value' => 'done', 'weight' => 1], ['value' => 'todo', 'weight' => 0]]),
            ]],
            ['table' => 'users', 'count' => 6, 'fields' => [
                field('status', weights: [['value' => 'active', 'weight' => 1]]),
            ]],
        ],
    ], $overrides);
}

function field(string $column, ?string $semantic = null, ?array $samples = null, ?array $weights = null, ?float $nullRate = null): array
{
    return ['column' => $column, 'semantic' => $semantic, 'samples' => $samples, 'weights' => $weights, 'null_rate' => $nullRate, 'true_rate' => null, 'min' => null, 'max' => null, 'present_when' => null];
}

function useAi(array|Throwable $response): FakeAIProvider
{
    config(['ai-seeder.ai.enabled' => true]);
    app()->instance(AIProviderInterface::class, $fake = new FakeAIProvider($response));

    return $fake;
}

function runSeeder(array $options = []): int
{
    return Artisan::call('ai:seed', ['--seed' => 7, '--size' => 'small', '--no-interaction' => true, ...$options]);
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-15 12:00:00');
    SaasSchema::create();
    $this->setEnvironment('local');
});

it('applies validated AI suggestions to generation', function () {
    useAi(suggestions());

    expect(runSeeder())->toBe(0);
    expect(Artisan::output())->toContain('AI planning: fake')->toContain('Application: A project management tool for agencies.');

    expect(DB::table('organizations')->count())->toBe(2)
        ->and(DB::table('users')->count())->toBe(6)
        ->and(DB::table('tasks')->count())->toBe(40)
        ->and(DB::table('tasks')->pluck('title')->unique()->diff(TASK_TITLES))->toBeEmpty()
        ->and(DB::table('tasks')->pluck('status')->unique()->all())->toBe(['done'])
        ->and(DB::table('users')->pluck('status')->unique()->all())->toBe(['active'])
        ->and(DB::table('users')->min('created_at'))->toBeGreaterThanOrEqual('2026-03-15 12:00:00');
});

it('caches the AI plan so re-runs make no call and reproduce the data', function () {
    $fake = useAi(suggestions());

    runSeeder();
    $first = DB::table('tasks')->orderBy('id')->get()->all();

    Schema::dropAllTables();
    SaasSchema::create();
    CarbonImmutable::setTestNow('2026-09-01 08:00:00'); // a later day: the cached anchor keeps dates identical

    runSeeder();

    expect(Artisan::output())->toContain('Reusing the saved AI plan')
        ->and($fake->calls)->toHaveCount(1)
        ->and(DB::table('tasks')->orderBy('id')->get()->all())->toEqual($first);

    runSeeder(['--replan' => true, '--dry-run' => true]);

    expect($fake->calls)->toHaveCount(2);
});

it('falls back to heuristics when the AI fails', function () {
    useAi(new RuntimeException('rate limited'));

    expect(runSeeder())->toBe(0);
    expect(Artisan::output())->toContain('AI planning failed (rate limited); using built-in heuristics')
        ->and(DB::table('organizations')->count())->toBe(3);
});

it('fails without changes when a scenario cannot be planned', function () {
    useAi(new RuntimeException('timeout'));

    expect(runSeeder(['--scenario' => 'busy agency']))->toBe(1);
    expect(Artisan::output())->toContain('timeout')->toContain('No database changes were made.')
        ->and(DB::table('organizations')->count())->toBe(0);
});

it('requires AI for scenarios', function () {
    expect(runSeeder(['--scenario' => 'busy agency', '--no-ai' => true]))->toBe(1);
    expect(Artisan::output())->toContain('--scenario needs AI planning, which is off (--no-ai)');
});

it('passes the scenario to the AI and skips it with --no-ai', function () {
    $fake = useAi(suggestions());

    runSeeder(['--scenario' => 'agency with 2 clients', '--dry-run' => true]);
    runSeeder(['--no-ai' => true, '--dry-run' => true]);

    expect($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['prompt'])->toContain('Scenario requested by the developer: agency with 2 clients');
});

it('sends structure but never row data to the AI', function () {
    runSeeder(['--no-ai' => true]);
    DB::table('users')->update(['first_name' => 'Zebediah-Secret']);

    $fake = useAi(suggestions());
    runSeeder(['--dry-run' => true, '--show-prompt' => true]);
    $output = Artisan::output();

    expect($fake->calls[0]['prompt'])->toContain('## tasks')->toContain('status')->toContain('references projects.id')
        ->not->toContain('Zebediah-Secret')->not->toContain('@example.')
        ->and($output)->toContain('AI prompt')->not->toContain('Zebediah-Secret');
});

it('neutralises hostile or invalid AI output', function () {
    useAi(suggestions([
        'timeline_months' => 9999,
        'tables' => [
            ['table' => 'users; drop table users', 'count' => 5, 'fields' => []],
            ['table' => 'failed_jobs', 'count' => 5, 'fields' => []],
            ['table' => 'organizations', 'count' => 1_000_000_000, 'fields' => [
                field('owner_id', semantic: 'text.title'),
                field('id', semantic: 'number.integer'),
                field('does_not_exist', samples: ['x']),
            ]],
            ['table' => 'tasks', 'count' => 20, 'fields' => [
                field('title', samples: ["'); DROP TABLE users; --"]),
                field('status', weights: [['value' => 'hacked', 'weight' => 100], ['value' => 'todo', 'weight' => 1]]),
                field('project_id', semantic: 'number.integer', nullRate: 1.0),
                field('completed_at', semantic: 'internet.email'),
            ]],
        ],
    ]));

    expect(runSeeder(['-v' => true]))->toBe(0);
    $output = Artisan::output();

    expect(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('failed_jobs'))->toBeTrue()
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        // Counts are clamped to ten times the baseline.
        ->and(DB::table('organizations')->count())->toBe(30)
        // Samples are data, bound as parameters, never executed.
        ->and(DB::table('tasks')->pluck('title')->unique()->all())->toBe(["'); DROP TABLE users; --"])
        ->and(DB::table('tasks')->pluck('status')->unique()->all())->toBe(['todo'])
        // References can't be rewired, and required ones can't be emptied.
        ->and(DB::table('organizations')->whereNull('owner_id')->count())->toBe(0)
        ->and(DB::table('tasks')->whereNull('project_id')->count())->toBe(0)
        ->and(DB::table('tasks')->whereNotIn('project_id', DB::table('projects')->pluck('id'))->count())->toBe(0)
        ->and($output)->toContain('Ignored unknown table [users; drop table users]')
        ->toContain('Ignored unknown column [organizations.does_not_exist]')
        ->toContain('Ignored value [hacked]')
        ->toContain("Ignored semantic [internet.email] for [tasks.completed_at]")
        // Timeline capped at 60 months.
        ->and(DB::table('organizations')->min('created_at'))->toBeGreaterThanOrEqual('2021-06-15 12:00:00');
});

it('caps the total rows', function () {
    config(['ai-seeder.max_rows' => 100]);
    useAi(suggestions(['tables' => [['table' => 'comments', 'count' => 100000, 'fields' => []]]]));

    runSeeder();

    $total = collect(['organizations', 'users', 'projects', 'project_user', 'tasks', 'comments', 'tags', 'taggables'])
        ->sum(fn ($table) => DB::table($table)->count());

    expect($total)->toBeLessThanOrEqual(100);
});
