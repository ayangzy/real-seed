<?php

namespace AISeeder\Generation;

use AISeeder\Graph\DependencyOrder;
use AISeeder\Graph\Edge;
use AISeeder\Graph\MorphSlot;
use AISeeder\Graph\SchemaGraph;
use AISeeder\Planning\TablePlan;
use RuntimeException;

/**
 * Picks parent rows for every reference in a generated row.
 *
 * Picks are scope-consistent: once a row is tied to a parent, later references are
 * drawn from rows that share an ancestor with it. A task's assignee comes from the
 * same organization as the task's project, and a comment's user and commented-on
 * record belong to the same tenant. Scoping follows required foreign keys only and
 * is derived from the graph, not from any domain knowledge.
 */
final class RelationshipResolver
{
    private const MAX_DEPTH = 3;

    /** @var array<string, RowStore> */
    public array $stores = [];

    /** @var array<string, array<string, list<Edge>>> table => [ancestor => path] */
    private array $paths = [];

    /** @var array<string, array<int, list<int>>> "table|ancestor" => [ancestor index => row indexes] */
    private array $ancestorGroups = [];

    /** @var array<string, list<int>> "table.column" => parent indexes still free for a unique reference */
    private array $uniquePools = [];

    /** @var array<string, list<int>> "table.column" => shuffled parent indexes dealt out first */
    private array $coverage = [];

    /** @var array<string, list<Edge|MorphSlot>> */
    private array $slots = [];

    public function __construct(
        private readonly SchemaGraph $graph,
        private readonly DependencyOrder $order,
    ) {
    }

    /**
     * References to resolve for a table: required edges first (they establish scope),
     * then optional edges, then polymorphic slots. Deferred and composite edges are skipped.
     *
     * @return list<Edge|MorphSlot>
     */
    public function slots(string $table): array
    {
        if (isset($this->slots[$table])) {
            return $this->slots[$table];
        }

        $edges = array_filter(
            $this->graph->parentEdges($table),
            fn (Edge $edge) => count($edge->columns) === 1 && ! $this->order->isDeferred($edge),
        );

        usort($edges, fn (Edge $a, Edge $b) => [$a->nullable, $a->isSelfReferencing(), $a->describe()] <=> [$b->nullable, $b->isSelfReferencing(), $b->describe()]);

        return $this->slots[$table] = [...$edges, ...$this->graph->morphSlots($table)];
    }

    /**
     * Every column that holds a reference, including deferred and composite ones.
     *
     * @return list<string>
     */
    public function referenceColumns(string $table): array
    {
        $columns = [];

        foreach ($this->graph->parentEdges($table) as $edge) {
            array_push($columns, ...$edge->columns);
        }

        foreach ($this->graph->morphSlots($table) as $slot) {
            array_push($columns, $slot->typeColumn, $slot->idColumn);
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param  callable(): (int|string)  $selfKey  Resolves the row's own primary key (for a first self-referencing row).
     * @param  int  $row  The row's position in this run, used to give every parent at least one child.
     * @return array{values: array<string, mixed>, after: ?int}
     */
    public function resolve(string $table, TablePlan $plan, callable $selfKey, SeededRandom $random, int $row = 0): array
    {
        $values = [];
        $after = null;
        $context = [];
        $primary = true;

        foreach ($this->slots($table) as $slot) {
            $covered = $primary && $slot instanceof Edge && ! $slot->nullable && ! $slot->isSelfReferencing();
            $primary = $primary && ! $covered;

            $picked = $slot instanceof Edge
                ? $this->resolveEdge($table, $slot, $plan, $context, $selfKey, $random, $values, $covered ? $row : null)
                : $this->resolveMorph($slot, $plan, $context, $random, $values);

            if ($picked !== null) {
                [$parentTable, $index] = $picked;
                $time = $this->stores[$parentTable]->time($index);
                $after = $after === null ? $time : max($after, $time);
            }
        }

        return ['values' => $values, 'after' => $after];
    }

    /**
     * @return array{string, int}|null The parent table and row index picked.
     */
    private function resolveEdge(string $table, Edge $edge, TablePlan $plan, array &$context, callable $selfKey, SeededRandom $random, array &$values, ?int $coverageRow = null): ?array
    {
        $column = $edge->columns[0];
        $field = $plan->field($column);
        $store = $this->stores[$edge->parent];
        $values[$column] = null;

        if ($edge->nullable && $random->chance((float) ($field?->option('null_rate') ?? 0.0))) {
            return null;
        }

        if ($edge->isSelfReferencing() && $store->count === 0) {
            // The first row can only point at itself.
            if (! $edge->nullable) {
                $values[$column] = $selfKey();
            }

            return null;
        }

        if ($store->count === 0) {
            if ($edge->nullable) {
                return null;
            }

            throw new RuntimeException("[{$table}.{$column}] requires rows in [{$edge->parent}], but it has none.");
        }

        if ($this->graph->schema->table($table)->isUnique($column)) {
            $index = $this->drawUnique($table, $column, $store, $random);

            if ($index === null) {
                if ($edge->nullable) {
                    return null;
                }

                throw new RuntimeException("Every row of [{$edge->parent}] is already referenced by the unique column [{$table}.{$column}].");
            }
        } elseif ($coverageRow !== null && $coverageRow < $store->count) {
            // Real data rarely has a parent with no children (an organization without
            // members, a project without tasks), so the first rows cover every parent.
            $index = $this->coverageOrder($edge, $store, $random)[$coverageRow];
        } else {
            $candidates = $this->scopedCandidates($edge->parent, $context, $edge->isSelfReferencing() ? $table : null, $field?->option('scope'));

            if ($candidates === [] && $edge->nullable) {
                return null;
            }

            $index = $this->pick($candidates ?: null, $store->count, (string) ($field?->option('selection') ?? 'skewed'), $random);
        }

        $values[$column] = $store->value($index, $edge->parentColumns[0]);
        $this->addToContext($context, $edge->parent, $index);

        return [$edge->parent, $index];
    }

    /**
     * @return array{string, int}|null
     */
    private function resolveMorph(MorphSlot $slot, TablePlan $plan, array &$context, SeededRandom $random, array &$values): ?array
    {
        $values[$slot->typeColumn] = null;
        $values[$slot->idColumn] = null;

        $idField = $plan->field($slot->idColumn);

        if ($slot->nullable && $random->chance((float) ($idField?->option('null_rate') ?? 0.0))) {
            return null;
        }

        $weights = (array) ($plan->field($slot->typeColumn)?->option('weights') ?? []);
        $options = [];

        foreach ($slot->targets as $morphClass => $target) {
            $store = $this->stores[$target] ?? null;

            if ($store === null || $store->count === 0) {
                continue;
            }

            $candidates = $this->scopedCandidates($target, $context, scope: $idField?->option('scope'));

            if ($candidates === []) {
                continue;
            }

            $options[$morphClass] = [$target, $candidates, (float) ($weights[$morphClass] ?? $store->count)];
        }

        if ($options === []) {
            if ($slot->nullable) {
                return null;
            }

            throw new RuntimeException("No rows are available for the polymorphic relation [{$slot->table}.{$slot->name}].");
        }

        $morphClass = $random->weighted(array_map(fn (array $option) => $option[2], $options));
        [$target, $candidates] = $options[$morphClass];
        $store = $this->stores[$target];

        $index = $this->pick($candidates, $store->count, 'skewed', $random);
        $key = $this->graph->schema->table($target)->primaryKey[0];

        $values[$slot->typeColumn] = $morphClass;
        $values[$slot->idColumn] = $store->value($index, $key);
        $this->addToContext($context, $target, $index);

        return [$target, $index];
    }

    /**
     * Row indexes of $table that share an ancestor already chosen for this row, trying the
     * nearest ancestor first and widening when it leaves nothing to pick (a comment's user
     * owns no projects, so the comment's project comes from the same organization instead).
     *
     * Null means "no shared ancestor, any row will do"; an empty list means none fit.
     * The field's "scope" option can name the ancestor to use, or disable scoping with false.
     *
     * @param  array<string, int>  $context
     * @return list<int>|null
     */
    private function scopedCandidates(string $table, array $context, ?string $growingTable = null, mixed $scope = null): ?array
    {
        if ($scope === false || $scope === 'none') {
            return null;
        }

        $sharesAncestor = false;

        foreach ($context as $ancestor => $ancestorIndex) {
            if ($ancestor === $table || (is_string($scope) && $ancestor !== $scope)
                || ($path = $this->pathsFrom($table)[$ancestor] ?? null) === null) {
                continue;
            }

            if ($growingTable !== null) {
                // The table is still being generated, so only its incremental one-hop index is usable.
                if (count($path) !== 1) {
                    continue;
                }

                $value = $this->stores[$ancestor]->value($ancestorIndex, $path[0]->parentColumns[0]);
                $candidates = $this->stores[$table]->group($path[0]->columns[0], $value);
            } else {
                $candidates = $this->ancestorGroup($table, $ancestor)[$ancestorIndex] ?? [];
            }

            if ($candidates !== []) {
                return $candidates;
            }

            $sharesAncestor = true;
        }

        return $sharesAncestor ? [] : null;
    }

    /**
     * @param  array<string, int>  $context
     */
    private function addToContext(array &$context, string $table, int $index): void
    {
        $context[$table] ??= $index;

        foreach ($this->pathsFrom($table) as $ancestor => $path) {
            if (! isset($context[$ancestor]) && ($ancestorIndex = $this->walk($table, $index, $path)) !== null) {
                $context[$ancestor] = $ancestorIndex;
            }
        }
    }

    /**
     * Ancestors reachable through required references, nearest first.
     *
     * @return array<string, list<Edge>>
     */
    private function pathsFrom(string $table): array
    {
        if (isset($this->paths[$table])) {
            return $this->paths[$table];
        }

        $paths = [];
        $queue = [[$table, []]];

        while ($queue !== []) {
            [$current, $path] = array_shift($queue);

            if (count($path) >= self::MAX_DEPTH) {
                continue;
            }

            foreach ($this->graph->parentEdges($current) as $edge) {
                if ($edge->nullable || $edge->isSelfReferencing() || count($edge->columns) !== 1
                    || $edge->parent === $table || isset($paths[$edge->parent]) || ! isset($this->stores[$edge->parent])) {
                    continue;
                }

                $paths[$edge->parent] = [...$path, $edge];
                $queue[] = [$edge->parent, $paths[$edge->parent]];
            }
        }

        return $this->paths[$table] = $paths;
    }

    /**
     * @param  list<Edge>  $path
     */
    private function walk(string $table, int $index, array $path): ?int
    {
        foreach ($path as $edge) {
            $value = $this->stores[$table]->value($index, $edge->columns[0]);
            $index = $this->stores[$edge->parent]->indexOf($edge->parentColumns[0], $value);

            if ($index === null) {
                return null;
            }

            $table = $edge->parent;
        }

        return $index;
    }

    /**
     * @return array<int, list<int>>
     */
    private function ancestorGroup(string $table, string $ancestor): array
    {
        $key = "{$table}|{$ancestor}";

        if (! isset($this->ancestorGroups[$key])) {
            $groups = [];
            $path = $this->pathsFrom($table)[$ancestor];

            for ($i = 0, $count = $this->stores[$table]->count; $i < $count; $i++) {
                if (($ancestorIndex = $this->walk($table, $i, $path)) !== null) {
                    $groups[$ancestorIndex][] = $i;
                }
            }

            $this->ancestorGroups[$key] = $groups;
        }

        return $this->ancestorGroups[$key];
    }

    /**
     * @return list<int>
     */
    private function coverageOrder(Edge $edge, RowStore $parent, SeededRandom $random): array
    {
        return $this->coverage[$edge->key()] ??= $this->shuffled(range(0, $parent->count - 1), $random);
    }

    /**
     * Deterministic Fisher-Yates shuffle.
     *
     * @param  list<int>  $items
     * @return list<int>
     */
    private function shuffled(array $items, SeededRandom $random): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $random->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    private function drawUnique(string $table, string $column, RowStore $parent, SeededRandom $random): ?int
    {
        $key = "{$table}.{$column}";

        if (! isset($this->uniquePools[$key])) {
            $edge = $this->graph->edgeForColumn($table, $column);
            $child = $this->stores[$table];
            $pool = [];

            for ($i = 0; $i < $parent->count; $i++) {
                // Skip parents that existing rows already reference.
                if ($child->group($column, $parent->value($i, $edge->parentColumns[0])) === []) {
                    $pool[] = $i;
                }
            }

            $this->uniquePools[$key] = $this->shuffled($pool, $random);
        }

        return array_pop($this->uniquePools[$key]);
    }

    /**
     * @param  list<int>|null  $candidates
     */
    private function pick(?array $candidates, int $count, string $selection, SeededRandom $random): int
    {
        $size = $candidates === null ? $count : count($candidates);
        $position = $selection === 'uniform' ? $random->int(0, $size - 1) : $random->skewedIndex($size);

        return $candidates === null ? $position : $candidates[$position];
    }
}
