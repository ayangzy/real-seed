<?php

use AISeeder\Graph\CircularDependencyException;
use AISeeder\Graph\DependencyResolver;
use AISeeder\Graph\SchemaGraph;
use AISeeder\Schema\SchemaReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('orders parents before children and breaks nullable cycles', function () {
    $graph = analyzeSaasFixture()->graph;
    $order = (new DependencyResolver)->resolve($graph);

    $position = array_flip($order->tables);

    foreach ($graph->edges() as $edge) {
        if (! $edge->isSelfReferencing() && ! $order->isDeferred($edge)) {
            expect($position[$edge->parent])->toBeLessThan($position[$edge->child], $edge->describe());
        }
    }

    expect($order->deferred)->toHaveCount(1)
        ->and($order->deferred[0]->describe())->toBe('organizations.owner_id -> users.id')
        ->and($position['projects'])->toBeLessThan($position['comments'])
        ->and($position['tasks'])->toBeLessThan($position['comments']);
});

it('is deterministic', function () {
    $graph = analyzeSaasFixture()->graph;

    expect((new DependencyResolver)->resolve($graph)->tables)
        ->toBe((new DependencyResolver)->resolve($graph)->tables);
});

it('fails clearly on a cycle of required foreign keys', function () {
    Schema::create('a', fn (Blueprint $t) => $t->id());
    Schema::create('b', function (Blueprint $t) {
        $t->id();
        $t->foreignId('a_id')->constrained('a');
    });
    Schema::table('a', fn (Blueprint $t) => $t->foreignId('b_id')->default(0)->constrained('b'));

    $graph = SchemaGraph::build((new SchemaReader)->read(app('db')->connection()));

    (new DependencyResolver)->resolve($graph);
})->throws(CircularDependencyException::class, 'Tables [a, b]');

it('computes the tables a subset requires', function () {
    $graph = analyzeSaasFixture()->graph;

    expect((new DependencyResolver)->requiredClosure($graph, ['tasks']))
        ->toBe(['organizations', 'projects', 'tasks', 'users']);
});
