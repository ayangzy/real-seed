<?php

use Ayangzy\RealSeed\Schema\SchemaReader;
use Ayangzy\RealSeed\Tests\Fixtures\SaasSchema;
use Ayangzy\RealSeed\Validation\GenerationException;
use Ayangzy\RealSeed\Validation\GenerationValidator;

beforeEach(function () {
    SaasSchema::create();
    $this->tasks = (new SchemaReader)->read(app('db')->connection())->table('tasks');
    $this->row = ['id' => 1, 'project_id' => 1, 'assignee_id' => null, 'title' => 'Ship it', 'status' => 'todo', 'completed_at' => null, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00'];
});

it('accepts a valid row', function () {
    (new GenerationValidator)->validate($this->tasks, $this->row);
})->throwsNoExceptions();

it('rejects invalid rows', function (array $changes, string $message) {
    (new GenerationValidator)->validate($this->tasks, array_merge($this->row, $changes));
})->throws(GenerationException::class)->with([
    'enum value' => [['status' => 'maybe'], 'not one of'],
    'missing required' => [['title' => null], 'required'],
    'unknown column' => [['hacked' => 1], 'not a column'],
    'updated before created' => [['updated_at' => '2025-01-01 00:00:00'], 'earlier than'],
]);

it('rejects rows missing a required column entirely', function () {
    $row = $this->row;
    unset($row['title']);

    (new GenerationValidator)->validate($this->tasks, $row);
})->throws(GenerationException::class, 'was not generated');
