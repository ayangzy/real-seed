<?php

namespace Ayangzy\RealSeed\Planning;

use Ayangzy\RealSeed\Analysis\ProjectAnalysis;
use Ayangzy\RealSeed\Graph\DependencyOrder;
use Ayangzy\RealSeed\Graph\DependencyResolver;
use Ayangzy\RealSeed\Graph\Edge;
use Ayangzy\RealSeed\Semantics\FieldInferrer;
use Ayangzy\RealSeed\Semantics\ReferenceData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Builds a generation plan without AI, from the relationship graph and field inference.
 */
final class HeuristicPlanner
{
    private const LOOKUP_ENTITIES = [
        'tag', 'category', 'role', 'permission', 'status', 'type', 'country', 'currency', 'language', 'plan',
        'priority', 'label', 'setting', 'industry', 'skill', 'unit', 'tax', 'region', 'state', 'city',
    ];

    private const META_COLUMNS = ['id', 'created_at', 'updated_at', 'deleted_at', 'uuid', 'ulid'];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        private readonly ProjectAnalysis $analysis,
        private readonly DependencyResolver $resolver = new DependencyResolver,
    ) {
    }

    public function plan(PlanOptions $options): GenerationPlan
    {
        $this->notes = [];
        $graph = $this->analysis->graph;
        $order = $this->resolver->resolve($graph);
        $preset = SizePreset::named($options->size);

        [$targets, $dependencies] = $this->selection($options, $order);

        $counts = $this->estimateCounts($order, $preset);

        if ($options->count !== null) {
            $counts = $this->scaleToTotal($counts, $options->count, $targets, $order);
        }

        $inferrer = new FieldInferrer($this->analysis);
        $small = $this->estimateCounts($order, SizePreset::named('small'));
        $tables = [];

        foreach ($order->tables as $table) {
            $schema = $graph->schema->table($table);
            $existing = $options->fresh ? 0 : ($options->existingCounts[$table] ?? 0);

            $count = match (true) {
                isset($targets[$table]) => $counts[$table],
                // A dependency that already has rows is reused, otherwise the minimum is generated.
                isset($dependencies[$table]) => $existing > 0 ? 0 : $small[$table],
                default => 0,
            };

            if ($count > 0 && ($reason = $this->unsupportedReason($table)) !== null) {
                $this->notes[] = "Skipping [{$table}]: {$reason}";
                $count = 0;
            }

            $fields = $inferrer->inferTable($schema);

            // A currencies or countries table can't have more rows than there are real entries.
            foreach ($fields as $field) {
                if (is_string($catalog = $field->option('catalog'))) {
                    $count = min($count, ReferenceData::size($catalog));
                }
            }

            $tables[$table] = new TablePlan($table, $count, $fields);
        }

        $now = ($options->now ?? CarbonImmutable::now())->startOfHour();

        return new GenerationPlan(
            seed: $options->seed,
            locale: $options->locale,
            start: $now->subMonths($preset->historyMonths),
            end: $now,
            tables: $tables,
            scenario: $options->scenario,
        );
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * Resolves --only / --except into tables to generate and dependencies to satisfy.
     *
     * @return array{array<string, true>, array<string, true>}
     */
    private function selection(PlanOptions $options, DependencyOrder $order): array
    {
        $all = $order->tables;

        foreach ([...($options->only ?? []), ...$options->except] as $table) {
            if (! in_array($table, $all, true)) {
                throw new PlanningException("Unknown table [{$table}]. Known tables: ".implode(', ', $all).'.');
            }
        }

        $targets = $options->only !== null
            ? array_fill_keys($options->only, true)
            : array_fill_keys(array_diff($all, $options->except), true);

        $dependencies = [];

        foreach ($this->resolver->requiredClosure($this->analysis->graph, array_keys($targets)) as $table) {
            if (isset($targets[$table])) {
                continue;
            }

            if (in_array($table, $options->except, true)) {
                if (($options->fresh ? 0 : ($options->existingCounts[$table] ?? 0)) === 0) {
                    throw new PlanningException("Cannot skip [{$table}]: other tables require it and it has no rows.");
                }

                continue;
            }

            $dependencies[$table] = true;
        }

        return [$targets, $dependencies];
    }

    /**
     * @return array<string, int>
     */
    private function estimateCounts(DependencyOrder $order, SizePreset $preset): array
    {
        $graph = $this->analysis->graph;
        $counts = [];

        foreach ($order->tables as $table) {
            $schema = $graph->schema->table($table);
            $required = array_values(array_filter(
                $graph->parentEdges($table),
                fn (Edge $edge) => ! $edge->nullable && ! $edge->isSelfReferencing() && ! $order->isDeferred($edge),
            ));

            $parentCounts = array_map(fn (Edge $edge) => $counts[$edge->parent] ?? $preset->root, $required);

            foreach ($graph->morphSlots($table) as $slot) {
                if (! $slot->nullable && $slot->targets !== []) {
                    $parentCounts[] = (int) ceil(array_sum(array_map(fn ($target) => $counts[$target] ?? 0, $slot->targets)) / count($slot->targets));
                }
            }

            $count = match (true) {
                $graph->isPivot($table) && count($parentCounts) >= 2 => min(array_product($parentCounts), max($parentCounts) * 3),
                $parentCounts === [] => $this->isLookup($table) ? $preset->lookup : $preset->root,
                $this->hasUniqueRequiredReference($table, $required) => (int) ceil(max($parentCounts) * 0.9),
                default => max($parentCounts) * $preset->fanout,
            };

            $counts[$table] = max(1, min($count, $preset->cap));
        }

        return $counts;
    }

    /**
     * Scales targeted tables so the total is close to the requested count, keeping ratios.
     *
     * @param  array<string, int>  $counts
     * @param  array<string, true>  $targets
     * @return array<string, int>
     */
    private function scaleToTotal(array $counts, int $total, array $targets, DependencyOrder $order): array
    {
        $current = array_sum(array_intersect_key($counts, $targets));

        if ($current === 0) {
            return $counts;
        }

        $factor = $total / $current;

        foreach ($targets as $table => $_) {
            $counts[$table] = max(1, (int) round($counts[$table] * $factor));
        }

        // One-to-one references can't have more rows than their parent.
        foreach ($order->tables as $table) {
            foreach ($this->analysis->graph->parentEdges($table) as $edge) {
                if (! $edge->nullable && count($edge->columns) === 1 && $this->analysis->schema->table($table)->isUnique($edge->columns[0])) {
                    $counts[$table] = min($counts[$table], $counts[$edge->parent] ?? $counts[$table]);
                }
            }
        }

        return $counts;
    }

    private function hasUniqueRequiredReference(string $table, array $required): bool
    {
        foreach ($required as $edge) {
            if (count($edge->columns) === 1 && $this->analysis->schema->table($table)->isUnique($edge->columns[0])) {
                return true;
            }
        }

        return false;
    }

    private function isLookup(string $table): bool
    {
        $entity = Str::afterLast(Str::singular(strtolower($table)), '_');

        if (in_array($entity, self::LOOKUP_ENTITIES, true)) {
            return true;
        }

        $content = array_diff(array_keys($this->analysis->schema->table($table)->columns), self::META_COLUMNS);

        return count($content) <= 2;
    }

    private function unsupportedReason(string $table): ?string
    {
        foreach ($this->analysis->graph->parentEdges($table) as $edge) {
            if (! $edge->nullable && count($edge->columns) > 1) {
                return 'composite foreign keys are not supported yet.';
            }
        }

        foreach ($this->analysis->graph->morphSlots($table) as $slot) {
            if (! $slot->nullable && $slot->targets === []) {
                return "no model declares the targets of the polymorphic relation [{$slot->name}].";
            }
        }

        return null;
    }
}
