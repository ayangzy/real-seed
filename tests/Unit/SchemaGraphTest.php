<?php

use Ayangzy\RealSeed\Graph\Edge;

beforeEach(function () {
    $this->analysis = analyzeSaasFixture();
    $this->graph = $this->analysis->graph;
});

it('builds edges from foreign keys', function () {
    $edge = $this->graph->edgeForColumn('tasks', 'project_id');

    expect($edge->parent)->toBe('projects')
        ->and($edge->nullable)->toBeFalse()
        ->and($edge->source)->toBe(Edge::SOURCE_FOREIGN_KEY);
});

it('adds edges from model relations where no foreign key exists', function () {
    expect($this->graph->edgeForColumn('project_user', 'user_id')->parent)->toBe('users')
        ->and($this->graph->edgeForColumn('project_user', 'project_id')->source)->toBe(Edge::SOURCE_RELATION);
});

it('detects polymorphic slots and their targets', function () {
    [$comments] = $this->graph->morphSlots('comments');
    [$taggables] = $this->graph->morphSlots('taggables');

    expect($comments->targets)->toBe([
        \Ayangzy\RealSeed\Tests\Fixtures\Models\Project::class => 'projects',
        \Ayangzy\RealSeed\Tests\Fixtures\Models\Task::class => 'tasks',
    ])->and($taggables->targets)->toBe([\Ayangzy\RealSeed\Tests\Fixtures\Models\Project::class => 'projects']);
});

it('detects pivot tables', function () {
    expect($this->graph->isPivot('project_user'))->toBeTrue()
        ->and($this->graph->isPivot('taggables'))->toBeTrue()
        ->and($this->graph->isPivot('comments'))->toBeFalse()
        ->and($this->graph->entityTables())->toBe(['comments', 'organizations', 'projects', 'tags', 'tasks', 'users']);
});

it('collects enums from PHP casts and database constraints', function () {
    expect($this->analysis->enum('users', 'status')->values)->toBe(['active', 'invited', 'suspended'])
        ->and($this->analysis->enum('users', 'status')->labels)->toBe(['Active', 'Invited', 'Suspended'])
        ->and($this->analysis->enum('tasks', 'status')->class)->toBeNull()
        ->and($this->analysis->enums)->toHaveCount(2);
});
