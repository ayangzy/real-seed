<?php

namespace AISeeder\AI;

use AISeeder\Planning\GenerationPlan;

/**
 * The instructions, prompt, and response schema for AI planning. The AI is asked to
 * describe data, never to produce rows, SQL, or code.
 */
final class PlanPrompt
{
    public static function instructions(): string
    {
        return <<<'TEXT'
        You plan realistic synthetic data for a Laravel application's development and staging databases.

        You receive the application's database structure. Infer what the application does from its
        tables, columns, relationships, enums, and model names, then describe how a realistically
        populated instance of this particular application would look.

        Rules:
        - Only mention tables and columns that appear in the structure. Never invent any.
        - Do not produce rows, SQL, or code. Describe distributions, quantities, and example values.
        - Row counts are relative to each other: parents fewer than children, reference tables small.
          Keep the overall size close to the baseline unless the scenario asks for more or less.
        - For text columns that users see (names, titles, subjects, descriptions, messages, notes),
          give 15-40 varied, realistic samples that fit the application and scenario. Write them in
          the language and culture of the requested locale. Samples must be clearly fictional:
          never real people, real private contact details, or real customer records.
        - For enum/status columns give weights reflecting realistic proportions.
        - Use present_when when a column only makes sense in some states
          (e.g. cancelled_at only when status is cancelled).
        - Only change a column's semantic when the baseline is clearly wrong.
        - Omit anything you have no better suggestion for; the baseline is used instead.
        TEXT;
    }

    public static function prompt(ApplicationContext $context, GenerationPlan $base, ?string $scenario): string
    {
        $scenarioText = $scenario !== null && trim($scenario) !== ''
            ? 'Scenario requested by the developer: '.trim($scenario)
            : 'No scenario was given: plan a typical, healthy, actively used instance of this application.';

        return implode("\n\n", [
            $scenarioText,
            "Locale: {$base->locale}",
            'Baseline timeline: '.$base->start->diffInMonths($base->end).' months of history up to '.$base->end->toDateString().'.',
            'Assignable semantics: '.implode(', ', ApplicationContext::assignableSemantics()),
            "# Application structure\n\n".$context->toText(),
        ]);
    }

    /**
     * Arrays of objects rather than maps keep the schema valid for strict structured-output modes.
     */
    public static function schema(): array
    {
        $nullableNumber = ['type' => ['number', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'domain' => ['type' => 'string', 'description' => 'One sentence describing what the application does.'],
                'timeline_months' => ['type' => ['integer', 'null'], 'description' => 'Months of history to generate.'],
                'tables' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'table' => ['type' => 'string'],
                            'count' => ['type' => ['integer', 'null']],
                            'fields' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'column' => ['type' => 'string'],
                                        'semantic' => ['type' => ['string', 'null'], 'enum' => [...ApplicationContext::assignableSemantics(), null]],
                                        'samples' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                                        'weights' => [
                                            'type' => ['array', 'null'],
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => ['value' => ['type' => 'string'], 'weight' => ['type' => 'number']],
                                                'required' => ['value', 'weight'],
                                            ],
                                        ],
                                        'null_rate' => $nullableNumber,
                                        'true_rate' => $nullableNumber,
                                        'min' => $nullableNumber,
                                        'max' => $nullableNumber,
                                        'present_when' => [
                                            'type' => ['object', 'null'],
                                            'properties' => [
                                                'column' => ['type' => 'string'],
                                                'values' => ['type' => 'array', 'items' => ['type' => 'string']],
                                            ],
                                            'required' => ['column', 'values'],
                                        ],
                                    ],
                                    'required' => ['column', 'semantic', 'samples', 'weights', 'null_rate', 'true_rate', 'min', 'max', 'present_when'],
                                ],
                            ],
                        ],
                        'required' => ['table', 'count', 'fields'],
                    ],
                ],
            ],
            'required' => ['domain', 'timeline_months', 'tables'],
        ];
    }
}
