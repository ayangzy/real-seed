<?php

use Ayangzy\RealSeed\Semantics\Vocabulary;
use Ayangzy\RealSeed\Tests\Fixtures\SaasSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    SaasSchema::create();

    Schema::create('workflows', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique();
        $table->text('description');
        $table->string('bank_name');
        $table->string('product_name');
    });

    $this->setEnvironment('local');
});

it('writes a readable baseline before AI refinement', function () {
    Artisan::call('real:seed', ['--size' => 'small', '--seed' => 3, '--locale' => 'ng', '--no-interaction' => true]);

    $taskTitles = Vocabulary::samples('tasks', 'title');
    $projectNames = Vocabulary::samples('projects', 'name');
    $commentLines = Vocabulary::samples('comments', 'text');

    expect(DB::table('tasks')->pluck('title')->diff($taskTitles))->toBeEmpty()
        ->and(DB::table('projects')->pluck('name')->diff($projectNames))->toBeEmpty()
        ->and(DB::table('comments')->pluck('body')->every(fn ($body) => collect($commentLines)->contains(fn ($line) => str_contains($body, $line))))->toBeTrue()
        ->and(DB::table('workflows')->pluck('bank_name')->diff(Vocabulary::banks('NG')))->toBeEmpty()
        ->and(DB::table('workflows')->pluck('product_name')->diff(Vocabulary::samples('products', 'name')))->toBeEmpty();
});

it('never produces lorem ipsum or novel excerpts', function () {
    Artisan::call('real:seed', ['--size' => 'medium', '--seed' => 9, '--no-interaction' => true]);

    $text = collect(['tasks' => ['title'], 'projects' => ['name'], 'comments' => ['body'], 'workflows' => ['name', 'description'], 'tags' => ['name']])
        ->flatMap(fn ($columns, $table) => DB::table($table)->get($columns)->flatMap(fn ($row) => array_values((array) $row)))
        ->implode(' ');

    foreach (['Alice', 'Rabbit', 'Hatter', 'lorem', 'ipsum', 'voluptat', 'e-business', 'synergi', 'paradigm'] as $nonsense) {
        expect(stripos($text, $nonsense))->toBeFalse("found \"{$nonsense}\"");
    }
});

it('builds unique names from the table name', function () {
    Artisan::call('real:seed', ['--size' => 'medium', '--seed' => 1, '--no-interaction' => true]);

    $names = DB::table('workflows')->pluck('name');

    expect($names->unique())->toHaveCount($names->count())
        ->and($names->every(fn ($name) => str_ends_with($name, 'Workflow') || preg_match('/Workflow-\d+$/', $name)))->toBeTrue();
});
