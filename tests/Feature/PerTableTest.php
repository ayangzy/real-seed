<?php

use Ayangzy\RealSeed\AI\AIProviderInterface;
use Ayangzy\RealSeed\Tests\Fixtures\FakeAIProvider;
use Ayangzy\RealSeed\Tests\Fixtures\SaasSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const SAAS_TABLES = ['organizations', 'users', 'projects', 'project_user', 'tasks', 'comments', 'tags', 'taggables'];

beforeEach(function () {
    SaasSchema::create();
    $this->setEnvironment('local');
});

function perTable(array $options): int
{
    return Artisan::call('real:seed', ['--seed' => 1, '--no-interaction' => true, ...$options]);
}

it('gives every table exactly the requested number of rows', function (int $rows) {
    expect(perTable(['--per-table' => $rows]))->toBe(0);

    foreach (SAAS_TABLES as $table) {
        expect(DB::table($table)->count())->toBe($rows, $table);
    }
})->with([1, 5, 25]);

it('ignores the AI\'s counts when --per-table is given', function () {
    app()->instance(AIProviderInterface::class, new FakeAIProvider([
        'domain' => 'x', 'timeline_months' => null,
        'tables' => [
            ['table' => 'tasks', 'count' => 400, 'states' => null, 'fields' => []],
            ['table' => 'users', 'count' => 1, 'states' => null, 'fields' => []],
        ],
    ]));

    perTable(['--per-table' => 7]);

    expect(DB::table('tasks')->count())->toBe(7)
        ->and(DB::table('users')->count())->toBe(7);
});

it('applies to the selected tables only with --only', function () {
    perTable(['--only' => 'tasks,comments', '--per-table' => 4]);

    expect(DB::table('tasks')->count())->toBe(4)
        ->and(DB::table('comments')->count())->toBe(4)
        // Parents added for them stay minimal.
        ->and(DB::table('projects')->count())->toBeLessThanOrEqual(4)
        ->and(DB::table('tags')->count())->toBe(0);
});

it('respects real limits', function () {
    Schema::create('currencies', function (Blueprint $table) {
        $table->id();
        $table->char('code', 3)->unique();
        $table->string('name');
    });

    perTable(['--per-table' => 50]);

    expect(DB::table('currencies')->count())->toBe(30) // only 30 real currencies exist
        ->and(DB::table('tasks')->count())->toBe(50);
});

it('refuses to combine --per-table with --count', function () {
    expect(perTable(['--per-table' => 5, '--count' => 100]))->toBe(1);
    expect(Artisan::output())->toContain('Use either --count (a total) or --per-table (rows for each table), not both.');
});

it('rejects invalid values', function () {
    expect(perTable(['--per-table' => 'lots']))->toBe(1);
    expect(Artisan::output())->toContain('--per-table must be a positive whole number.');
});
