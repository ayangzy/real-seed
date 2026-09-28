<?php

use Ayangzy\RealSeed\Tests\Fixtures\SaasSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    SaasSchema::create();
    $this->setEnvironment('local');

    // Real data the app depends on.
    DB::table('organizations')->insert([
        ['id' => 1, 'name' => 'Real Company Ltd', 'slug' => 'real-company', 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00'],
        ['id' => 2, 'name' => 'Another Real Co', 'slug' => 'another-real', 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00'],
    ]);
    DB::table('tags')->insert([['id' => 1, 'name' => 'urgent'], ['id' => 2, 'name' => 'billing']]);

    config(['realseed.protected_tables' => ['organizations', 'tags']]);
});

function protectedSnapshot(): array
{
    return [
        DB::table('organizations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        DB::table('tags')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

it('never adds to or changes protected tables, and links new data to them', function () {
    $before = protectedSnapshot();

    expect(Artisan::call('real:seed', ['--size' => 'small', '--seed' => 1, '--no-interaction' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Protected:               organizations, tags');

    expect(protectedSnapshot())->toBe($before)
        ->and(DB::table('users')->count())->toBeGreaterThan(0)
        ->and(DB::table('users')->pluck('organization_id')->unique()->sort()->values()->all())->toBe([1, 2])
        ->and(DB::table('taggables')->whereNotIn('tag_id', [1, 2])->count())->toBe(0);
});

it('keeps protected parents intact under --fresh', function () {
    // organizations.owner_id points back at users, so only tags can stay protected here.
    config(['realseed.protected_tables' => ['tags']]);
    $tags = DB::table('tags')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    $this->artisan('real:seed', ['--size' => 'small', '--seed' => 1, '--fresh' => true])
        ->expectsQuestion('This will permanently delete the rows listed above. Type the database name ['.config('database.connections.testbench.database').'] to continue', config('database.connections.testbench.database'))
        ->assertExitCode(0);

    expect(DB::table('tags')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($tags)
        ->and(DB::table('users')->count())->toBeGreaterThan(0)
        ->and(DB::table('taggables')->whereNotIn('tag_id', [1, 2])->count())->toBe(0);
});

it('refuses --fresh when protected rows point back at regenerated tables', function () {
    // organizations.owner_id -> users: deleting users would orphan protected organizations.
    expect(Artisan::call('real:seed', ['--size' => 'small', '--fresh' => true, '--no-interaction' => true]))->toBe(1);
    expect(Artisan::output())->toContain('--fresh would delete rows in protected tables [organizations]');
});

it('refuses --fresh when it would have to delete protected rows', function () {
    config(['realseed.protected_tables' => ['comments']]);
    DB::table('users')->insert(['id' => 50, 'organization_id' => 1, 'first_name' => 'Real', 'last_name' => 'Person', 'email' => 'real@example.com', 'password' => 'x', 'status' => 'active']);
    DB::table('comments')->insert(['user_id' => 50, 'commentable_type' => 'x', 'commentable_id' => 1, 'body' => 'A real comment']);

    expect(Artisan::call('real:seed', ['--size' => 'small', '--fresh' => true, '--no-interaction' => true]))->toBe(1);
    expect(Artisan::output())->toContain('--fresh would delete rows in protected tables [comments]')
        ->and(DB::table('comments')->where('body', 'A real comment')->count())->toBe(1);
});

it('refuses to generate a protected table with --only', function () {
    expect(Artisan::call('real:seed', ['--only' => 'tags', '--no-interaction' => true]))->toBe(1);
    expect(Artisan::output())->toContain('[tags] is in protected_tables');
});

it('skips dependents of an empty protected table with a note', function () {
    DB::table('organizations')->delete();

    // Every table in this fixture depends on organizations, so nothing can be generated.
    expect(Artisan::call('real:seed', ['--size' => 'small', '--seed' => 1, '--no-interaction' => true]))->toBe(1);
    expect(Artisan::output())->toContain('Skipping [users]: it needs rows in [organizations (protected)], which is empty')
        ->toContain('Nothing can be generated')
        ->not->toContain('Generation failed')
        ->and(DB::table('organizations')->count())->toBe(0);
});
