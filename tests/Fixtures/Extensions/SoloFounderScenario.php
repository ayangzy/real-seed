<?php

namespace AISeeder\Tests\Fixtures\Extensions;

use AISeeder\Analysis\ProjectAnalysis;
use AISeeder\Extension\ScenarioProvider;
use AISeeder\Planning\GenerationPlan;

class SoloFounderScenario implements ScenarioProvider
{
    public function description(): ?string
    {
        return 'A single founder running one organization.';
    }

    public function suggestions(ProjectAnalysis $analysis, GenerationPlan $plan): array
    {
        return ['tables' => [
            ['table' => 'organizations', 'count' => 1, 'fields' => []],
            ['table' => 'users', 'count' => 1, 'fields' => [['column' => 'status', 'weights' => [['value' => 'active', 'weight' => 1]]]]],
        ]];
    }
}
