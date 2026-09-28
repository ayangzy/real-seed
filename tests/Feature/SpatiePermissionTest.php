<?php

use Ayangzy\RealSeed\Tests\Fixtures\Permissions\Member;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The table layout of spatie/laravel-permission's migration, with relations declared
 * from both sides (morphToMany on the member, morphedByMany on permissions and roles).
 */
beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('permissions', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::create('roles', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::create('model_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->index(['model_id', 'model_type']);
        $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
        $table->primary(['permission_id', 'model_id', 'model_type']);
    });

    Schema::create('model_has_roles', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->index(['model_id', 'model_type']);
        $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
        $table->primary(['role_id', 'model_id', 'model_type']);
    });

    Schema::create('role_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
        $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
        $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
        $table->primary(['permission_id', 'role_id']);
    });

    // An existing user with a high id: generated users continue from it, so user ids can
    // never coincide with permission or role ids and a wrong link can't pass by accident.
    DB::table('users')->insert(['id' => 1000, 'name' => 'Existing', 'created_at' => now(), 'updated_at' => now()]);

    config(['realseed.model_paths' => [__DIR__.'/../Fixtures/Permissions']]);
    $this->setEnvironment('local');
});

it('understands the pivot from both sides of the relation', function () {
    $graph = app(\Ayangzy\RealSeed\Analysis\ProjectAnalyzer::class)
        ->analyze(app('db')->connection(), app('migrator'), ['model_paths' => [__DIR__.'/../Fixtures/Permissions']])
        ->graph;

    [$slot] = $graph->morphSlots('model_has_permissions');

    expect($slot->targets)->toBe([Member::class => 'users'])
        ->and($graph->edgeForColumn('model_has_permissions', 'model_id'))->toBeNull()
        ->and($graph->edgeForColumn('model_has_permissions', 'permission_id')->parent)->toBe('permissions');
});

it('seeds spatie/laravel-permission tables with valid polymorphic links', function (int $seed) {
    expect(Artisan::call('real:seed', ['--size' => 'small', '--seed' => $seed, '--no-interaction' => true]))->toBe(0, Artisan::output());

    foreach (['model_has_permissions' => 'permission_id', 'model_has_roles' => 'role_id'] as $pivot => $key) {
        expect(DB::table($pivot)->count())->toBeGreaterThan(0)
            ->and(DB::table($pivot)->pluck('model_type')->unique()->all())->toBe([Member::class])
            // model_id points at users, never at permissions or roles.
            ->and(DB::table($pivot)->whereNotIn('model_id', DB::table('users')->pluck('id'))->count())->toBe(0);
    }

    expect(DB::table('role_has_permissions')->count())->toBeGreaterThan(0);
})->with([1, 2, 3, 42]);
