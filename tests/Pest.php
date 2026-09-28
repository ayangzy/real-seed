<?php

use AISeeder\Analysis\ProjectAnalysis;
use AISeeder\Analysis\ProjectAnalyzer;
use AISeeder\Tests\Fixtures\SaasSchema;
use AISeeder\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');

function analyzeSaasFixture(): ProjectAnalysis
{
    if (! \Illuminate\Support\Facades\Schema::hasTable('users')) {
        SaasSchema::create();
    }

    return app(ProjectAnalyzer::class)->analyze(app('db')->connection(), app('migrator'), [
        'model_paths' => [__DIR__.'/Fixtures/Models'],
        'excluded_tables' => config('ai-seeder.excluded_tables'),
    ]);
}
