<?php

namespace Ayangzy\RealSeed\Graph;

use Ayangzy\RealSeed\Analysis\ModelInfo;
use Ayangzy\RealSeed\Analysis\RelationInfo;
use Ayangzy\RealSeed\Schema\DatabaseSchema;
use Ayangzy\RealSeed\Schema\TableSchema;

/**
 * The application's relationship graph.
 *
 * Database foreign keys are the source of truth. Model relations fill the gaps many
 * applications have: missing FK constraints, pivot tables, and polymorphic targets.
 */
final class SchemaGraph
{
    private const PIVOT_EXTRA_COLUMNS = ['id', 'created_at', 'updated_at'];

    /**
     * @param  array<string, Edge>  $edges  Keyed by Edge::key().
     * @param  array<string, MorphSlot>  $morphSlots  Keyed by "table.name".
     * @param  array<string, true>  $pivots
     */
    private function __construct(
        public readonly DatabaseSchema $schema,
        private readonly array $edges,
        private readonly array $morphSlots,
        private readonly array $pivots,
    ) {
    }

    /**
     * @param  array<string, ModelInfo>  $models  Keyed by table name.
     */
    public static function build(DatabaseSchema $schema, array $models = []): self
    {
        $edges = [];
        $explicitPivots = [];

        foreach ($schema->tables as $table) {
            foreach ($table->foreignKeys as $fk) {
                if (! $schema->has($fk->foreignTable)) {
                    continue;
                }

                $edge = new Edge($table->name, $fk->columns, $fk->foreignTable, $fk->foreignColumns, self::allNullable($table, $fk->columns), Edge::SOURCE_FOREIGN_KEY);
                $edges[$edge->key()] = $edge;
            }
        }

        $morphSlots = self::detectMorphSlots($schema);
        $declaredMorphs = [];

        foreach ($models as $table => $model) {
            foreach ($model->relations as $relation) {
                self::addRelationEdges($schema, $model, $relation, $edges, $morphSlots, $explicitPivots);

                if ($relation->type === RelationInfo::MORPH_TO) {
                    $declaredMorphs[$model->table.'.'.self::unqualify($relation->morphType)] = true;
                }
            }
        }

        $morphSlots = self::confirmedMorphSlots($schema, $models, $morphSlots, $edges, $declaredMorphs);
        $edges = self::addConventionEdges($schema, $edges, $morphSlots);

        ksort($edges);
        ksort($morphSlots);

        $pivots = $explicitPivots;

        foreach ($schema->tables as $table) {
            if (self::looksLikePivot($table, $edges, $morphSlots)) {
                $pivots[$table->name] = true;
            }
        }

        return new self($schema, $edges, $morphSlots, $pivots);
    }

    /**
     * Adds polymorphic targets found outside the models (existing rows, configuration).
     *
     * @param  array<string, array<string, string>>  $targets  "table.name" => [morph class => target table]
     */
    public function withMorphTargets(array $targets): self
    {
        $slots = $this->morphSlots;

        foreach ($targets as $key => $map) {
            if (! isset($slots[$key])) {
                continue;
            }

            foreach ($map as $morphClass => $table) {
                if ($this->schema->has($table)) {
                    $slots[$key] = $slots[$key]->withTarget($morphClass, $table);
                }
            }
        }

        return new self($this->schema, $this->edges, $slots, $this->pivots);
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return $this->schema->names();
    }

    /**
     * @return list<Edge>
     */
    public function edges(): array
    {
        return array_values($this->edges);
    }

    /**
     * Edges where the given table is the child.
     *
     * @return list<Edge>
     */
    public function parentEdges(string $table): array
    {
        return array_values(array_filter($this->edges, fn (Edge $edge) => $edge->child === $table));
    }

    /**
     * Edges where the given table is the parent.
     *
     * @return list<Edge>
     */
    public function childEdges(string $table): array
    {
        return array_values(array_filter($this->edges, fn (Edge $edge) => $edge->parent === $table));
    }

    /**
     * @return list<MorphSlot>
     */
    public function morphSlots(?string $table = null): array
    {
        return array_values(array_filter(
            $this->morphSlots,
            fn (MorphSlot $slot) => $table === null || $slot->table === $table,
        ));
    }

    /**
     * The column in $table that holds a reference, if any.
     */
    public function edgeForColumn(string $table, string $column): ?Edge
    {
        foreach ($this->parentEdges($table) as $edge) {
            if (in_array($column, $edge->columns, true)) {
                return $edge;
            }
        }

        return null;
    }

    public function isPivot(string $table): bool
    {
        return isset($this->pivots[$table]);
    }

    /**
     * Tables that represent application entities rather than join tables.
     *
     * @return list<string>
     */
    public function entityTables(): array
    {
        return array_values(array_filter($this->tables(), fn (string $table) => ! $this->isPivot($table)));
    }

    public function relationshipCount(): int
    {
        return count($this->edges) + array_sum(array_map(fn (MorphSlot $slot) => count($slot->targets), $this->morphSlots));
    }

    /**
     * @param  array<string, Edge>  $edges
     * @param  array<string, MorphSlot>  $morphSlots
     * @param  array<string, true>  $pivots
     */
    private static function addRelationEdges(DatabaseSchema $schema, ModelInfo $model, RelationInfo $relation, array &$edges, array &$morphSlots, array &$pivots): void
    {
        $addEdge = function (string $child, string $column, string $parent, string $parentColumn) use ($schema, &$edges) {
            $childTable = $schema->table($child);

            if ($childTable === null || ! $childTable->hasColumn($column) || ! $schema->table($parent)?->hasColumn($parentColumn)) {
                return;
            }

            $edge = new Edge($child, [$column], $parent, [$parentColumn], self::allNullable($childTable, [$column]), Edge::SOURCE_RELATION);

            // A database foreign key on the same column always wins.
            $edges[$edge->key()] ??= $edge;
        };

        switch ($relation->type) {
            case RelationInfo::BELONGS_TO:
                $addEdge($model->table, $relation->foreignKey, $relation->relatedTable, $relation->ownerKey);
                break;

            case RelationInfo::HAS_ONE:
            case RelationInfo::HAS_MANY:
                $addEdge($relation->relatedTable, $relation->foreignKey, $model->table, $model->keyName);
                break;

            case RelationInfo::BELONGS_TO_MANY:
                if ($schema->has($relation->pivotTable)) {
                    $pivots[$relation->pivotTable] = true;
                    $addEdge($relation->pivotTable, $relation->foreignPivotKey, $model->table, $model->keyName);
                    $addEdge($relation->pivotTable, $relation->relatedPivotKey, $relation->relatedTable, $relation->ownerKey);
                }
                break;

            case RelationInfo::MORPH_TO_MANY:
                // Declared on the morphing parent, e.g. Post::tags() over "taggables".
                if ($schema->has($relation->pivotTable)) {
                    $pivots[$relation->pivotTable] = true;
                    $addEdge($relation->pivotTable, $relation->relatedPivotKey, $relation->relatedTable, $relation->ownerKey);
                    self::addMorphTarget($morphSlots, $relation->pivotTable, $relation->morphType, $relation->morphClass, $model->table);
                }
                break;

            case RelationInfo::MORPH_ONE:
            case RelationInfo::MORPH_MANY:
                self::addMorphTarget($morphSlots, $relation->relatedTable, $relation->morphType, $model->morphClass, $model->table);
                break;
        }
    }

    private static function addMorphTarget(array &$morphSlots, string $table, string $typeColumn, string $morphClass, string $targetTable): void
    {
        $typeColumn = self::unqualify($typeColumn);

        foreach ($morphSlots as $key => $slot) {
            if ($slot->table === $table && $slot->typeColumn === $typeColumn) {
                $morphSlots[$key] = $slot->withTarget($morphClass, $targetTable);

                return;
            }
        }
    }

    /**
     * Keeps only the {name}_type / {name}_id pairs that really are polymorphic. The names
     * alone prove nothing: bank_accounts.account_type may be an enum of account kinds
     * (checking, savings) and account_id a plain reference. A pair counts when a model
     * declares it, or when nothing contradicts it: the id column isn't a foreign key or
     * belongsTo, and the type column has no fixed list of non-model values.
     *
     * @param  array<string, MorphSlot>  $slots
     * @param  array<string, Edge>  $edges
     * @param  array<string, true>  $declaredMorphs  "table.type_column" pairs with a morphTo relation
     * @return array<string, MorphSlot>
     */
    private static function confirmedMorphSlots(DatabaseSchema $schema, array $models, array $slots, array $edges, array $declaredMorphs): array
    {
        foreach ($slots as $key => $slot) {
            if ($slot->targets !== [] || isset($declaredMorphs["{$slot->table}.{$slot->typeColumn}"])) {
                continue;
            }

            $idIsReference = isset($edges[$slot->table.'.'.$slot->idColumn]);
            $cast = $models[$slot->table]->casts[$slot->typeColumn] ?? null;
            $fixedValues = is_string($cast) && enum_exists($cast)
                ? array_map(fn ($case) => $case instanceof \BackedEnum ? $case->value : $case->name, $cast::cases())
                : $schema->table($slot->table)->column($slot->typeColumn)?->allowedValues;

            $nonModelValues = $fixedValues !== null && array_filter(
                $fixedValues,
                fn ($value) => ! self::isModelType((string) $value),
            ) !== [];

            if ($idIsReference || $nonModelValues) {
                unset($slots[$key]);
            }
        }

        return $slots;
    }

    private static function isModelType(string $value): bool
    {
        $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($value) ?? $value;

        return class_exists($class) && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class);
    }

    /**
     * Laravel naming convention for references without a database constraint or model
     * relation: an unconstrained account_id points at accounts.id when that table exists.
     *
     * @param  array<string, Edge>  $edges
     * @param  array<string, MorphSlot>  $morphSlots
     * @return array<string, Edge>
     */
    private static function addConventionEdges(DatabaseSchema $schema, array $edges, array $morphSlots): array
    {
        $morphColumns = [];

        foreach ($morphSlots as $slot) {
            $morphColumns["{$slot->table}.{$slot->idColumn}"] = true;
        }

        foreach ($schema->tables as $table) {
            foreach ($table->columns as $column) {
                $key = "{$table->name}.{$column->name}";

                if (! str_ends_with($column->name, '_id') || isset($edges[$key]) || isset($morphColumns[$key])
                    || in_array($column->name, $table->primaryKey, true)) {
                    continue;
                }

                $parent = $schema->table(\Illuminate\Support\Str::plural(substr($column->name, 0, -3)));
                $parentKey = $parent?->singlePrimaryKey();

                if ($parentKey === null || $parentKey->family() !== $column->family()
                    || ! in_array($column->family(), ['integer', 'uuid', 'string'], true)) {
                    continue;
                }

                $edges[$key] = new Edge($table->name, [$column->name], $parent->name, [$parentKey->name], $column->nullable, Edge::SOURCE_CONVENTION);
            }
        }

        return $edges;
    }

    /**
     * @return array<string, MorphSlot>
     */
    private static function detectMorphSlots(DatabaseSchema $schema): array
    {
        $slots = [];

        foreach ($schema->tables as $table) {
            foreach ($table->columns as $column) {
                if (! str_ends_with($column->name, '_type')) {
                    continue;
                }

                $name = substr($column->name, 0, -5);
                $idColumn = $table->column("{$name}_id");

                if ($idColumn !== null) {
                    $slots["{$table->name}.{$name}"] = new MorphSlot($table->name, $name, $column->name, $idColumn->name, $idColumn->nullable);
                }
            }
        }

        return $slots;
    }

    /**
     * @param  array<string, Edge>  $edges
     * @param  array<string, MorphSlot>  $morphSlots
     */
    private static function looksLikePivot(TableSchema $table, array $edges, array $morphSlots): bool
    {
        $referenceColumns = [];

        foreach ($edges as $edge) {
            if ($edge->child === $table->name) {
                array_push($referenceColumns, ...$edge->columns);
            }
        }

        foreach ($morphSlots as $slot) {
            if ($slot->table === $table->name) {
                array_push($referenceColumns, $slot->typeColumn, $slot->idColumn);
            }
        }

        $referenceColumns = array_unique($referenceColumns);

        if (count($referenceColumns) < 2) {
            return false;
        }

        $others = array_diff(array_keys($table->columns), $referenceColumns, self::PIVOT_EXTRA_COLUMNS);

        return $others === [];
    }

    private static function allNullable(TableSchema $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! ($table->column($column)?->nullable ?? true)) {
                return false;
            }
        }

        return true;
    }

    private static function unqualify(string $column): string
    {
        return str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
    }
}
