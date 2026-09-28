<?php

namespace Ayangzy\RealSeed\Tests\Fixtures\Extensions;

use Ayangzy\RealSeed\Analysis\ProjectAnalysis;
use Ayangzy\RealSeed\Extension\ScenarioProvider;
use Ayangzy\RealSeed\Planning\GenerationPlan;

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
