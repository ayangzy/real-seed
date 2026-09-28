<?php

namespace Ayangzy\RealSeed\Extension;

use Ayangzy\RealSeed\Analysis\ProjectAnalysis;
use Ayangzy\RealSeed\Planning\GenerationPlan;

/**
 * A named, reusable scenario, used with --scenario=<name>. Register under "scenarios".
 *
 * Suggestions use the same format as AI output (see Ayangzy\RealSeed\AI\PlanPrompt::schema())
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
