<?php

namespace Ayangzy\RealSeed\Tests\Fixtures\Extensions;

use Ayangzy\RealSeed\Analysis\ProjectAnalysis;
use Ayangzy\RealSeed\Extension\ApplicationAnalyzer;
use Ayangzy\RealSeed\Planning\GenerationPlan;

class TaskTitleAnalyzer implements ApplicationAnalyzer
{
    public function suggestions(ProjectAnalysis $analysis, GenerationPlan $plan): array
    {
        return ['tables' => [['table' => 'tasks', 'fields' => [['column' => 'title', 'samples' => ['House-style task A', 'House-style task B']]]]]];
    }
}
