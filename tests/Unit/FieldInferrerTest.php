<?php

use AISeeder\Semantics\FieldInferrer;
use AISeeder\Semantics\Semantic;

beforeEach(function () {
    $this->analysis = analyzeSaasFixture();
    $this->infer = fn (string $table, string $column) => (new FieldInferrer($this->analysis))
        ->infer($this->analysis->schema->table($table), $this->analysis->schema->table($table)->column($column));
});

it('infers semantics from names, types, casts and the graph', function (string $table, string $column, string $semantic) {
    expect(($this->infer)($table, $column)->semantic)->toBe($semantic);
})->with([
    ['users', 'id', Semantic::KEY],
    ['users', 'first_name', Semantic::FIRST_NAME],
    ['users', 'last_name', Semantic::LAST_NAME],
    ['users', 'email', Semantic::EMAIL],
    ['users', 'password', Semantic::PASSWORD],
    ['users', 'status', Semantic::ENUM],
    ['users', 'organization_id', Semantic::REFERENCE],
    ['users', 'created_at', Semantic::CREATED_AT],
    ['users', 'deleted_at', Semantic::DELETED_AT],
    ['organizations', 'name', Semantic::COMPANY],
    ['organizations', 'slug', Semantic::SLUG],
    ['projects', 'name', Semantic::NAME],
    ['projects', 'budget', Semantic::MONEY],
    ['tasks', 'title', Semantic::TITLE],
    ['tasks', 'status', Semantic::ENUM],
    ['tasks', 'completed_at', Semantic::PAST],
    ['comments', 'body', Semantic::PARAGRAPH],
    ['comments', 'commentable_type', Semantic::MORPH_TYPE],
    ['comments', 'commentable_id', Semantic::MORPH_ID],
    ['tags', 'name', Semantic::NAME],
]);

it('links workflow timestamps to matching states', function () {
    expect(($this->infer)('tasks', 'completed_at')->option('present_when'))->toBe(['status' => ['done']]);
});

it('weights positive states above negative ones', function () {
    $weights = ($this->infer)('users', 'status')->option('weights');

    expect($weights['active'])->toBeGreaterThan($weights['invited'])
        ->and($weights['invited'])->toBeGreaterThan($weights['suspended']);
});

it('sets null rates for nullable columns', function () {
    expect(($this->infer)('users', 'deleted_at')->option('null_rate'))->toBe(0.92)
        ->and(($this->infer)('tasks', 'assignee_id')->option('null_rate'))->toBe(0.15)
        ->and(($this->infer)('users', 'manager_id')->option('null_rate'))->toBe(0.3)
        ->and(($this->infer)('organizations', 'owner_id')->option('null_rate'))->toBe(0.0)
        ->and(($this->infer)('users', 'first_name')->option('null_rate'))->toBeNull();
});

it('only uses semantics from the closed vocabulary', function () {
    foreach ($this->analysis->schema->tables as $table) {
        foreach ((new FieldInferrer($this->analysis))->inferTable($table) as $plan) {
            expect(Semantic::all())->toContain($plan->semantic);
        }
    }
});
