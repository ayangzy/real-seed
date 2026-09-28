<?php

use Ayangzy\RealSeed\Generation\SeededRandom;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Property: whatever the schema, a run either generates consistent data or says up
 * front what it skips and why. It never fails half-way on a missing parent.
 *
 * Each case builds a random schema: chains of required foreign keys up to 8 tables
 * deep, optional links, and polymorphic tables no model can resolve (always skipped).
 */
it('never fails mid-run on missing parents', function (int $case) {
    $random = new SeededRandom($case);
    $tables = [];

    foreach (range(0, $random->int(4, 8)) as $i) {
        $name = "t{$i}";
        $parents = $tables === [] ? [] : array_unique(array_map(fn () => $random->pick($tables), range(1, $random->int(0, 2))));
        $optional = $tables !== [] && $random->chance(0.4) ? $random->pick($tables) : null;
        $unresolvable = $random->chance(0.25);

        Schema::create($name, function (Blueprint $table) use ($parents, $optional, $unresolvable) {
            $table->id();
            $table->string('name');

            foreach ($parents as $parent) {
                $table->foreignId("{$parent}_id")->constrained($parent);
            }

            if ($optional !== null && ! in_array($optional, $parents, true)) {
                $table->foreignId("{$optional}_ref_id")->nullable()->constrained($optional);
            }

            if ($unresolvable) {
                $table->morphs('owner');
            }
        });

        $tables[] = $name;
    }

    config(['realseed.model_paths' => []]);
    $this->setEnvironment('local');

    $exit = Artisan::call('real:seed', ['--size' => 'small', '--seed' => $case, '--no-interaction' => true]);
    $output = Artisan::output();

    expect($output)->not->toContain('Generation failed')
        ->and($exit === 0 || str_contains($output, 'Nothing can be generated'))->toBeTrue($output);

    // Every skip is explained before the plan runs.
    foreach ($tables as $table) {
        if (DB::table($table)->count() === 0) {
            expect($output)->toContain("Skipping [{$table}]");
        }
    }
})->with(range(1, 40));
