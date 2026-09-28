<?php

use Ayangzy\RealSeed\Planning\GenerationPlan;
use Ayangzy\RealSeed\Planning\HeuristicPlanner;
use Ayangzy\RealSeed\Planning\PlanningException;
use Ayangzy\RealSeed\Planning\PlanOptions;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->planner = new HeuristicPlanner(analyzeSaasFixture());
});

it('derives counts from the graph instead of using one number for every table', function () {
    $plan = $this->planner->plan(new PlanOptions(seed: 1, size: 'small'));

    expect($plan->count('organizations'))->toBe(3)
        ->and($plan->count('users'))->toBe(9)
        ->and($plan->count('tags'))->toBe(5)
        ->and($plan->count('projects'))->toBeGreaterThan($plan->count('users'))
        ->and($plan->count('comments'))->toBeGreaterThan($plan->count('projects'));
});

it('grows with the size preset', function () {
    $small = $this->planner->plan(new PlanOptions(seed: 1, size: 'small'))->totalRows();
    $medium = $this->planner->plan(new PlanOptions(seed: 1, size: 'medium'))->totalRows();
    $large = $this->planner->plan(new PlanOptions(seed: 1, size: 'large'))->totalRows();

    expect($small)->toBeLessThan($medium)->and($medium)->toBeLessThan($large);
});

it('reuses existing dependencies and generates missing ones', function () {
    $empty = $this->planner->plan(new PlanOptions(seed: 1, only: ['tasks']));
    $existing = $this->planner->plan(new PlanOptions(seed: 1, only: ['tasks'], existingCounts: ['projects' => 5, 'users' => 3, 'organizations' => 1]));

    expect($empty->generatedTables())->toEqualCanonicalizing(['organizations', 'users', 'projects', 'tasks'])
        ->and($existing->generatedTables())->toBe(['tasks']);
});

it('ignores existing rows when they will be wiped', function () {
    $plan = $this->planner->plan(new PlanOptions(seed: 1, only: ['tasks'], existingCounts: ['projects' => 5], fresh: true));

    expect($plan->count('projects'))->toBeGreaterThan(0);
});

it('rejects unknown tables and unskippable exclusions', function () {
    expect(fn () => $this->planner->plan(new PlanOptions(seed: 1, only: ['ghosts'])))->toThrow(PlanningException::class, 'Unknown table [ghosts]')
        ->and(fn () => $this->planner->plan(new PlanOptions(seed: 1, except: ['organizations'])))->toThrow(PlanningException::class, 'Cannot skip [organizations]');
});

it('anchors the timeline to the plan time', function () {
    $plan = $this->planner->plan(new PlanOptions(seed: 1, size: 'medium', now: CarbonImmutable::parse('2026-06-15 12:34:56')));

    expect($plan->end->toDateTimeString())->toBe('2026-06-15 12:00:00')
        ->and($plan->start->toDateTimeString())->toBe('2025-06-15 12:00:00');
});

it('round-trips through an array', function () {
    $plan = $this->planner->plan(new PlanOptions(seed: 5, size: 'small', now: CarbonImmutable::parse('2026-01-01')));

    expect(GenerationPlan::fromArray($plan->toArray())->toArray())->toBe($plan->toArray());
});
