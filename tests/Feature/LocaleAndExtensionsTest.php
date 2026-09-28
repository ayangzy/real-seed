<?php

use Ayangzy\RealSeed\Tests\Fixtures\Extensions;
use Ayangzy\RealSeed\Tests\Fixtures\FakeAIProvider;
use Ayangzy\RealSeed\Tests\Fixtures\SaasSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-15 12:00:00');
    SaasSchema::create();

    Schema::create('contacts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained();
        $table->string('first_name');
        $table->string('last_name');
        $table->string('phone');
        $table->string('city');
        $table->string('state');
        $table->string('postcode');
        $table->string('country');
        $table->string('currency', 3);
        $table->timestamps();
    });

    $this->setEnvironment('local');
});

function seedRun(array $options = []): int
{
    return Artisan::call('realseed', ['--seed' => 3, '--size' => 'small', '--no-interaction' => true, ...$options]);
}

it('generates coherent Nigerian data with --locale=ng', function () {
    expect(seedRun(['--locale' => 'ng', '--only' => 'contacts']))->toBe(0);

    $contacts = DB::table('contacts')->get();
    $states = (new ReflectionClassConstant(\Ayangzy\RealSeed\Locale\NigeriaLocale::class, 'STATES'))->getValue();

    expect($contacts)->not->toBeEmpty();

    foreach ($contacts as $contact) {
        expect($states)->toHaveKey($contact->state)
            ->and($states[$contact->state])->toContain($contact->city)
            ->and($contact->phone)->toMatch('/^(\+234 [789]\d{2}|0[789]\d{2}) \d{3} \d{4}$/')
            ->and($contact->postcode)->toMatch('/^\d{6}$/')
            ->and($contact->country)->toBe('Nigeria')
            ->and($contact->currency)->toBe('NGN');
    }
});

it('supports Faker locales and rejects unknown ones', function () {
    seedRun(['--locale' => 'pt_BR', '--only' => 'contacts']);

    expect(DB::table('contacts')->pluck('currency')->unique()->all())->toBe(['BRL']);

    expect(seedRun(['--locale' => 'atlantis']))->toBe(1);
    expect(Artisan::output())->toContain('Unknown locale [atlantis]');
});

it('uses custom locales registered in config', function () {
    config(['realseed.locales' => ['moon' => Extensions\MoonLocale::class]]);
    $this->refreshApplicationBindings();

    seedRun(['--locale' => 'moon', '--only' => 'contacts']);

    expect(DB::table('contacts')->pluck('city')->unique()->all())->toBe(['Tranquility Base'])
        ->and(DB::table('contacts')->pluck('currency')->unique()->all())->toBe(['MNC']);
});

it('applies custom field generators with the most specific registration winning', function () {
    config(['realseed.generators' => [
        '*.phone' => Extensions\FixedPhoneGenerator::class,
        'contacts.phone' => Extensions\ContactPhoneGenerator::class,
    ]]);
    $this->refreshApplicationBindings();

    seedRun(['--only' => 'contacts']);

    expect(DB::table('contacts')->pluck('phone')->unique()->all())->toBe(['contact-specific']);
});

it('applies row generators but keeps references with RealSeed', function () {
    config(['realseed.row_generators' => ['tasks' => Extensions\TaskRowGenerator::class]]);
    $this->refreshApplicationBindings();

    seedRun(['--only' => 'tasks']);

    $tasks = DB::table('tasks')->get();

    expect($tasks->every(fn ($task) => $task->title === "Task for project {$task->project_id}"))->toBeTrue()
        ->and($tasks->pluck('project_id'))->not->toContain(999999);
});

it('lets reference pickers choose among valid candidates only', function () {
    config(['realseed.reference_pickers' => [
        'tasks.assignee_id' => Extensions\NoAssigneePicker::class,
        'projects.owner_id' => Extensions\BogusOwnerPicker::class,
        'project_user.user_id' => Extensions\FirstMemberPicker::class,
    ]]);
    $this->refreshApplicationBindings();

    seedRun();

    expect(DB::table('tasks')->whereNotNull('assignee_id')->count())->toBe(0)
        ->and(DB::table('projects')->where('owner_id', 424242)->count())->toBe(0)
        ->and(DB::table('projects')->whereNull('owner_id')->count())->toBe(0)
        // Candidates are scoped to the project's organization; the picker takes the first member.
        ->and((int) DB::scalar('select count(*) from project_user pu join projects p on p.id = pu.project_id where pu.user_id != (select min(id) from users u where u.organization_id = p.organization_id)'))->toBe(0);
});

it('runs named scenarios without AI', function () {
    config(['realseed.scenarios' => ['solo' => Extensions\SoloFounderScenario::class]]);
    $this->refreshApplicationBindings();

    expect(seedRun(['--scenario' => 'solo', '--no-ai' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Scenario: solo')
        ->and(DB::table('organizations')->count())->toBe(1)
        ->and(DB::table('users')->count())->toBe(1)
        ->and(DB::table('users')->value('status'))->toBe('active');
});

it('applies application analyzers to every plan', function () {
    config(['realseed.analyzers' => [Extensions\TaskTitleAnalyzer::class]]);
    $this->refreshApplicationBindings();

    seedRun();

    expect(DB::table('tasks')->pluck('title')->unique()->sort()->values()->all())->toBe(['House-style task A', 'House-style task B']);
});

it('lets configuration override AI suggestions', function () {
    config([
        'realseed.ai.enabled' => true,
        'realseed.overrides' => [
            'users' => [
                'count' => 4,
                'fields' => [
                    'status' => ['weights' => ['suspended' => 1]],
                    'manager_id' => ['null_rate' => 1.0],
                ],
            ],
        ],
    ]);
    app()->instance(\Ayangzy\RealSeed\AI\AIProviderInterface::class, new FakeAIProvider([
        'domain' => 'x', 'timeline_months' => null,
        'tables' => [['table' => 'users', 'count' => 6, 'states' => null, 'fields' => [
            ['column' => 'status', 'semantic' => null, 'samples' => null, 'weights' => [['value' => 'active', 'weight' => 1]], 'null_rate' => null, 'true_rate' => null, 'min' => null, 'max' => null, 'present_when' => null],
        ]]],
    ]));

    seedRun();

    expect(DB::table('users')->count())->toBe(4)
        ->and(DB::table('users')->pluck('status')->unique()->all())->toBe(['suspended'])
        ->and(DB::table('users')->whereNotNull('manager_id')->count())->toBe(0);
});

it('fails clearly on a misconfigured extension', function () {
    config(['realseed.generators' => ['*.phone' => \stdClass::class]]);
    $this->refreshApplicationBindings();

    expect(seedRun(['--only' => 'contacts']))->toBe(1);
    expect(Artisan::output())->toContain('does not implement Ayangzy\RealSeed\Extension\FieldGenerator');
});
