<?php

namespace AISeeder\AI;

use AISeeder\Analysis\ProjectAnalysis;
use AISeeder\Planning\GenerationPlan;
use AISeeder\Semantics\Semantic;

/**
 * The compact description of the application sent to the AI.
 *
 * It contains structure only: table and column names, types, constraints, enum
 * values, relationships, model names, and the baseline plan. Database rows are
 * never read for it, so data in a staging copy of production cannot leak.
 */
final class ApplicationContext
{
    public function __construct(
        private readonly ProjectAnalysis $analysis,
        private readonly GenerationPlan $base,
    ) {
    }

    public function toText(): string
    {
        $graph = $this->analysis->graph;
        $lines = [];

        foreach ($this->base->tables as $table => $plan) {
            $schema = $this->analysis->schema->table($table);
            $model = $this->analysis->model($table);

            $header = "## {$table}";
            $header .= $model !== null ? " (model {$model->class})" : '';
            $header .= $graph->isPivot($table) ? ' [pivot]' : '';
            $header .= $plan->count > 0 ? " — baseline rows: {$plan->count}" : ' — not generated in this run';
            $lines[] = $header;

            foreach ($schema->columns as $column) {
                $field = $plan->field($column->name);
                $parts = ["- {$column->name}: {$column->type}"];

                if ($column->nullable) {
                    $parts[] = 'nullable';
                }

                if ($schema->isUnique($column->name) && $schema->primaryKey !== [$column->name]) {
                    $parts[] = 'unique';
                }

                if (($edge = $graph->edgeForColumn($table, $column->name)) !== null) {
                    $parts[] = "references {$edge->parent}.{$edge->parentColumns[0]}";
                }

                if (($enum = $this->analysis->enum($table, $column->name)) !== null) {
                    $labels = array_map(fn ($value, $label) => $value === $label ? (string) $value : "{$value} ({$label})", $enum->values, $enum->labels);
                    $parts[] = 'values: '.implode(', ', $labels);
                }

                if ($model?->casts[$column->name] ?? null) {
                    $parts[] = 'cast: '.class_basename($model->casts[$column->name]);
                }

                if ($column->comment) {
                    $parts[] = 'comment: '.mb_substr($column->comment, 0, 120);
                }

                if ($field !== null) {
                    $parts[] = "baseline semantic: {$field->semantic}";
                }

                $lines[] = implode('; ', $parts);
            }

            foreach ($graph->morphSlots($table) as $slot) {
                $lines[] = "- polymorphic {$slot->name}: ".($slot->targets === [] ? 'unknown targets' : implode(', ', $slot->targets));
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Semantics the AI may assign. Keys, references and polymorphic columns are structural
     * and are always derived from the schema, never from the AI.
     *
     * @return list<string>
     */
    public static function assignableSemantics(): array
    {
        return array_values(array_diff(
            Semantic::all(),
            [Semantic::KEY, Semantic::REFERENCE, Semantic::MORPH_TYPE, Semantic::MORPH_ID],
        ));
    }
}
