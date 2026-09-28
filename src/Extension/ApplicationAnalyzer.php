<?php

namespace AISeeder\Extension;

use AISeeder\Analysis\ProjectAnalysis;
use AISeeder\Planning\GenerationPlan;

/**
 * Adds application-specific understanding to every run, before AI planning: for
 * example, knowledge that invoices.number follows a house format, or that most
 * accounts in your product are on the free plan. Register under "analyzers".
 */
interface ApplicationAnalyzer
{
    /**
     * @return array Suggestions in the AI output format (see AISeeder\AI\PlanPrompt::schema()).
     */
    public function suggestions(ProjectAnalysis $analysis, GenerationPlan $plan): array;
}
