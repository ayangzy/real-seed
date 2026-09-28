<?php

namespace AISeeder\Extension;

use AISeeder\Analysis\ProjectAnalysis;
use AISeeder\Planning\GenerationPlan;

/**
 * A named, reusable scenario, used with --scenario=<name>. Register under "scenarios".
 *
 * Suggestions use the same format as AI output (see AISeeder\AI\PlanPrompt::schema())
 * and are validated the same way. When AI planning is available, description() is
 * also given to the AI as the scenario.
 */
interface ScenarioProvider
{
    public function description(): ?string;

    /**
     * @return array{tables?: list<array{table: string, count?: ?int, fields?: list<array>, states?: ?list<array>}>, timeline_months?: ?int}
     */
    public function suggestions(ProjectAnalysis $analysis, GenerationPlan $plan): array;
}
