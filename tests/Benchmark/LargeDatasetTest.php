<?php

use Ayangzy\RealSeed\Tests\Fixtures\SaasSchema;
use Ayangzy\RealSeed\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(TestCase::class);

/*
 * Opt-in: vendor/bin/pest --group=benchmark
 * Generates ~100k rows and checks time and memory stay within budget.
 */
it('generates 100k rows within memory and time budgets', function () {
    SaasSchema::create();
    $this->setEnvironment('local');

    $started = microtime(true);
    $exit = Artisan::call('real:seed', ['--seed' => 1, '--count' => 100000, '--size' => 'large', '--no-interaction' => true]);
    $seconds = microtime(true) - $started;

    $total = collect(['organizations', 'users', 'projects', 'project_user', 'tasks', 'comments', 'tags', 'taggables'])
        ->sum(fn ($table) => DB::table($table)->count());

    fwrite(STDERR, sprintf("\n%s: %s rows in %.1fs, peak memory %.0f MB\n", DB::getDriverName(), number_format($total), $seconds, memory_get_peak_usage(true) / 1048576));

    expect($exit)->toBe(0)
        ->and($total)->toBeGreaterThan(90000)
        ->and(memory_get_peak_usage(true))->toBeLessThan(256 * 1024 * 1024)
        ->and($seconds)->toBeLessThan(120);
})->group('benchmark');
