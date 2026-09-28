<?php

namespace Ayangzy\RealSeed\Graph;

/**
 * Orders tables so every parent is inserted before its children.
 *
 * Self-references never block ordering: rows can point at rows with lower keys in
 * the same table. Cycles between tables are broken by deferring nullable edges
 * (insert null, back-fill afterwards); a cycle made only of required edges fails.
 */
final class DependencyResolver
{
    /**
     * @param  list<string>|null  $tables  Restrict ordering to these tables (default: all).
     *
     * @throws CircularDependencyException
     */
    public function resolve(SchemaGraph $graph, ?array $tables = null): DependencyOrder
    {
        $tables ??= $graph->tables();
        $included = array_fill_keys($tables, true);

        $dependencies = array_values(array_filter(
            $this->dependencyEdges($graph),
            fn (Edge $edge) => isset($included[$edge->child], $included[$edge->parent]) && ! $edge->isSelfReferencing(),
        ));

        $deferred = [];

        while (true) {
            [$order, $remaining] = $this->topologicalSort($tables, $dependencies);

            if ($remaining === []) {
                return new DependencyOrder($order, $deferred);
            }

            // Break the cycle at the first (deterministically ordered) nullable edge inside it.
            $remainingSet = array_fill_keys($remaining, true);
            $breakable = null;

            foreach ($dependencies as $index => $edge) {
                if ($edge->nullable && isset($remainingSet[$edge->child], $remainingSet[$edge->parent])) {
                    $breakable = $index;
                    break;
                }
            }

            if ($breakable === null) {
                throw new CircularDependencyException($remaining);
            }

            $deferred[] = $dependencies[$breakable];
            unset($dependencies[$breakable]);
            $dependencies = array_values($dependencies);
        }
    }

    /**
     * Adds every table the given tables require through non-nullable references.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function requiredClosure(SchemaGraph $graph, array $tables): array
    {
        $required = array_fill_keys($tables, true);
        $queue = $tables;

        while ($queue !== []) {
            $table = array_shift($queue);

            foreach ($this->dependencyEdges($graph) as $edge) {
                if ($edge->child === $table && ! $edge->nullable && ! isset($required[$edge->parent])) {
                    $required[$edge->parent] = true;
                    $queue[] = $edge->parent;
                }
            }
        }

        $result = array_keys($required);
        sort($result);

        return $result;
    }

    /**
     * Foreign-key edges plus polymorphic targets, which must also exist before the child.
     *
     * @return list<Edge>
     */
    private function dependencyEdges(SchemaGraph $graph): array
    {
        $edges = $graph->edges();

        foreach ($graph->morphSlots() as $slot) {
            foreach ($slot->targets as $target) {
                $edges[] = new Edge($slot->table, [$slot->idColumn], $target, ['id'], $slot->nullable, 'morph');
            }
        }

        usort($edges, fn (Edge $a, Edge $b) => strcmp($a->describe(), $b->describe()));

        return $edges;
    }

    /**
     * Kahn's algorithm with alphabetical tie-breaking so the order is reproducible.
     *
     * @param  list<string>  $tables
     * @param  list<Edge>  $edges
     * @return array{list<string>, list<string>} [ordered, tables left in cycles]
     */
    private function topologicalSort(array $tables, array $edges): array
    {
        $inDegree = array_fill_keys($tables, 0);
        $children = array_fill_keys($tables, []);

        foreach ($edges as $edge) {
            $children[$edge->parent][$edge->child] = true;
        }

        foreach ($children as $parent => $set) {
            foreach (array_keys($set) as $child) {
                $inDegree[$child]++;
            }
        }

        $ready = array_keys(array_filter($inDegree, fn (int $degree) => $degree === 0));
        sort($ready);
        $order = [];

        while ($ready !== []) {
            $table = array_shift($ready);
            $order[] = $table;

            foreach (array_keys($children[$table]) as $child) {
                if (--$inDegree[$child] === 0) {
                    $ready[] = $child;
                    sort($ready);
                }
            }
        }

        $remaining = array_values(array_diff($tables, $order));
        sort($remaining);

        return [$order, $remaining];
    }
}
