<?php

namespace Ayangzy\RealSeed\Extension;

use Ayangzy\RealSeed\Analysis\ProjectAnalysis;
use Ayangzy\RealSeed\Planning\GenerationPlan;

/**
 * Adds application-specific understanding to every run, before AI planning: for
 * example, knowledge that invoices.number follows a house format, or that most
 * accounts in your product are on the free plan. Register under "analyzers".
 */
interface ApplicationAnalyzer
{
    /**
     * @return array Suggestions in the AI output format (see Ayangzy\RealSeed\AI\PlanPrompt::schema()).
     */
    public function suggestions(ProjectAnalysis $analysis, GenerationPlan $plan): array;
}
