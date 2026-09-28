<?php

use AISeeder\Planning\HeuristicPlanner;
use AISeeder\Planning\PlanOptions;
use AISeeder\Planning\PlanValidator;
use AISeeder\Semantics\Semantic;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->analysis = analyzeSaasFixture();
    $this->planOptions = new PlanOptions(seed: 1, size: 'small', now: CarbonImmutable::parse('2026-06-15'));
    $this->base = (new HeuristicPlanner($this->analysis))->plan($this->planOptions);
    $this->validator = new PlanValidator($this->analysis);
    $this->merge = fn (array $tables, ?PlanOptions $options = null) => $this->validator->merge($this->base, ['tables' => $tables], $options ?? $this->planOptions);
});

function suggest(string $table, array $fields, ?int $count = null): array
{
    return ['table' => $table, 'count' => $count, 'fields' => $fields];
}

it('accepts compatible semantic changes and rejects incompatible ones', function () {
    $plan = ($this->merge)([suggest('projects', [
        ['column' => 'name', 'semantic' => Semantic::COMPANY],
        ['column' => 'budget', 'semantic' => Semantic::EMAIL],
    ])]);

    expect($plan->table('projects')->field('name')->semantic)->toBe(Semantic::COMPANY)
        ->and($plan->table('projects')->field('budget')->semantic)->toBe(Semantic::MONEY)
        ->and(implode("\n", $this->validator->warnings()))->toContain('Ignored semantic [internet.email] for [projects.budget]');
});

it('lets the AI define values for an unconstrained text column', function () {
    $plan = ($this->merge)([suggest('tags', [
        ['column' => 'name', 'semantic' => Semantic::ENUM, 'weights' => [['value' => 'urgent', 'weight' => 2], ['value' => 'backlog', 'weight' => 1]]],
    ])]);

    expect($plan->table('tags')->field('name')->semantic)->toBe(Semantic::ENUM)
        ->and($plan->table('tags')->field('name')->option('weights'))->toBe(['urgent' => 2.0, 'backlog' => 1.0]);
});

it('never samples a unique column with fewer samples than rows', function () {
    $plan = ($this->merge)([suggest('organizations', [['column' => 'slug', 'semantic' => Semantic::NAME, 'samples' => ['acme']]])]);

    expect($plan->table('organizations')->field('slug')->option('samples'))->toBeNull();
});

it('validates workflow conditions', function () {
    $plan = ($this->merge)([suggest('tasks', [
        ['column' => 'completed_at', 'present_when' => ['column' => 'status', 'values' => ['done', 'imaginary']]],
        ['column' => 'assignee_id', 'present_when' => ['column' => 'title', 'values' => ['x']]],
    ])]);

    expect($plan->table('tasks')->field('completed_at')->option('present_when'))->toBe(['status' => ['done']]);
});

it('rescales to --count after applying AI counts and respects one-to-one limits', function () {
    $options = new PlanOptions(seed: 1, size: 'small', count: 100, now: CarbonImmutable::parse('2026-06-15'));
    $base = (new HeuristicPlanner($this->analysis))->plan($options);
    $plan = $this->validator->merge($base, ['tables' => [suggest('comments', [], 5000)]], $options);

    expect($plan->totalRows())->toBeGreaterThan(80)->toBeLessThan(120);
});

it('keeps structural columns out of the AI\'s reach', function () {
    $plan = ($this->merge)([suggest('tasks', [
        ['column' => 'id', 'semantic' => Semantic::UUID],
        ['column' => 'project_id', 'semantic' => Semantic::INTEGER, 'null_rate' => 1],
        ['column' => 'assignee_id', 'null_rate' => 0.5],
    ])]);

    expect($plan->table('tasks')->field('id')->semantic)->toBe(Semantic::KEY)
        ->and($plan->table('tasks')->field('project_id')->semantic)->toBe(Semantic::REFERENCE)
        ->and($plan->table('tasks')->field('project_id')->option('null_rate'))->toBeNull()
        ->and($plan->table('tasks')->field('assignee_id')->option('null_rate'))->toBe(0.5);
});
