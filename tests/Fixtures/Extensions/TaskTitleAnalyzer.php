<?php

namespace AISeeder\Tests\Fixtures\Extensions;

use AISeeder\Analysis\ProjectAnalysis;
use AISeeder\Extension\ApplicationAnalyzer;
use AISeeder\Planning\GenerationPlan;

class TaskTitleAnalyzer implements ApplicationAnalyzer
{
    public function suggestions(ProjectAnalysis $analysis, GenerationPlan $plan): array
    {
        return ['tables' => [['table' => 'tasks', 'fields' => [['column' => 'title', 'samples' => ['House-style task A', 'House-style task B']]]]]];
    }
}
