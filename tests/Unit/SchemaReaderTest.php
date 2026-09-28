<?php

use Ayangzy\RealSeed\Schema\SchemaReader;
use Ayangzy\RealSeed\Tests\Fixtures\SaasSchema;

beforeEach(function () {
    SaasSchema::create();
    $this->schema = (new SchemaReader)->read(app('db')->connection(), ['failed_jobs'], ['users.status', 'projects.budget']);
});

it('reads tables and skips excluded ones', function () {
    expect($this->schema->names())->toBe([
        'comments', 'organizations', 'project_user', 'projects', 'taggables', 'tags', 'tasks', 'users',
    ]);
});

it('reads columns with nullability, defaults and auto-increment', function () {
    $tasks = $this->schema->table('tasks');

    expect($tasks->column('id')->autoIncrement)->toBeTrue()
        ->and($tasks->column('assignee_id')->nullable)->toBeTrue()
        ->and($tasks->column('title')->isRequired())->toBeTrue()
        ->and($tasks->column('status')->hasDefault())->toBeTrue()
        ->and($tasks->primaryKey)->toBe(['id']);
});

it('reads enum values from check constraints', function () {
    expect($this->schema->table('tasks')->column('status')->allowedValues)
        ->toBe(['todo', 'in_progress', 'done', 'cancelled']);
});

it('reads composite primary keys, unique indexes and foreign keys', function () {
    expect($this->schema->table('project_user')->primaryKey)->toBe(['project_id', 'user_id'])
        ->and($this->schema->table('users')->isUnique('email'))->toBeTrue()
        ->and($this->schema->table('organizations')->foreignKeys[0]->foreignTable)->toBe('users');
});

it('drops excluded optional columns but keeps required ones', function () {
    expect($this->schema->table('projects')->hasColumn('budget'))->toBeFalse()
        ->and($this->schema->table('users')->hasColumn('status'))->toBeTrue();
});

it('produces a stable hash that changes with the structure', function () {
    $again = (new SchemaReader)->read(app('db')->connection(), ['failed_jobs'], ['users.status', 'projects.budget']);
    $wider = (new SchemaReader)->read(app('db')->connection(), ['failed_jobs']);

    expect($again->hash())->toBe($this->schema->hash())
        ->and($wider->hash())->not->toBe($this->schema->hash());
});

it('parses MySQL enum column types', function () {
    $column = \Ayangzy\RealSeed\Schema\ColumnSchema::fromArray([
        'name' => 'status', 'type_name' => 'enum', 'type' => "enum('a','it''s','c')",
        'nullable' => false, 'default' => null, 'auto_increment' => false, 'comment' => null, 'generation' => null,
    ]);

    expect($column->allowedValues)->toBe(['a', "it's", 'c']);
});
