<?php

use Ayangzy\RealSeed\Analysis\ProjectAnalysis;
use Ayangzy\RealSeed\Analysis\ProjectAnalyzer;
use Ayangzy\RealSeed\Tests\Fixtures\SaasSchema;
use Ayangzy\RealSeed\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');

function analyzeSaasFixture(): ProjectAnalysis
{
    if (! \Illuminate\Support\Facades\Schema::hasTable('users')) {
        SaasSchema::create();
    }

    return app(ProjectAnalyzer::class)->analyze(app('db')->connection(), app('migrator'), [
        'model_paths' => [__DIR__.'/Fixtures/Models'],
        'excluded_tables' => config('realseed.excluded_tables'),
    ]);
}
