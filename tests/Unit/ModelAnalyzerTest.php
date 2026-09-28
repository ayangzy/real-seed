<?php

use Ayangzy\RealSeed\Analysis\ModelAnalyzer;
use Ayangzy\RealSeed\Analysis\ModelFinder;
use Ayangzy\RealSeed\Analysis\RelationInfo;
use Ayangzy\RealSeed\Tests\Fixtures\Models;

it('finds concrete models in any directory', function () {
    expect((new ModelFinder)->find([__DIR__.'/../Fixtures/Models']))->toBe([
        Models\Comment::class, Models\Organization::class, Models\Project::class,
        Models\Tag::class, Models\Task::class, Models\User::class,
    ]);
});

it('extracts relations including untyped ones and isolates broken ones', function () {
    $analyzer = new ModelAnalyzer;
    $user = $analyzer->analyze(Models\User::class);

    $relations = collect($user->relations)->keyBy('name');

    expect($relations->keys()->sort()->values()->all())->toBe(['manager', 'organization', 'projects'])
        ->and($relations['organization']->type)->toBe(RelationInfo::BELONGS_TO)
        ->and($relations['organization']->foreignKey)->toBe('organization_id')
        ->and($relations['projects']->pivotTable)->toBe('project_user')
        ->and($analyzer->warnings())->toHaveCount(1)
        ->and($analyzer->warnings()[0])->toContain('User::broken');
});

it('describes polymorphic relations', function () {
    $project = (new ModelAnalyzer)->analyze(Models\Project::class);
    $relations = collect($project->relations)->keyBy('name');

    expect($relations['comments']->type)->toBe(RelationInfo::MORPH_MANY)
        ->and($relations['comments']->morphType)->toBe('commentable_type')
        ->and($relations['tags']->type)->toBe(RelationInfo::MORPH_TO_MANY)
        ->and($relations['tags']->pivotTable)->toBe('taggables');
});

it('reads casts', function () {
    $user = (new ModelAnalyzer)->analyze(Models\User::class);

    expect($user->casts['status'])->toBe(\Ayangzy\RealSeed\Tests\Fixtures\Enums\UserStatus::class)
        ->and($user->table)->toBe('users');
});
