<?php

namespace Ayangzy\RealSeed\Analysis;

use Ayangzy\RealSeed\Graph\SchemaGraph;
use Ayangzy\RealSeed\Schema\SchemaReader;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * Runs the analysis pipeline: migrations -> schema -> models -> enums -> factories -> graph.
 */
final class ProjectAnalyzer
{
    /** @var list<string> */
    private array $notices = [];

    public function __construct(
        private readonly SchemaReader $schemaReader,
        private readonly ModelFinder $modelFinder,
        private readonly ModelAnalyzer $modelAnalyzer,
        private readonly EnumAnalyzer $enumAnalyzer,
        private readonly FactoryAnalyzer $factoryAnalyzer,
    ) {
    }

    /**
     * @param  array{model_paths?: list<string>, migration_paths?: list<string>, excluded_tables?: list<string>, excluded_columns?: list<string>, morph_targets?: array<string, list<string>>}  $options
     */
    public function analyze(Connection $connection, Migrator $migrator, array $options = []): ProjectAnalysis
    {
        $migrations = MigrationStatus::inspect($migrator, $connection->getName(), $options['migration_paths'] ?? []);

        $schema = $this->schemaReader->read(
            $connection,
            $options['excluded_tables'] ?? [],
            $options['excluded_columns'] ?? [],
        );

        $models = [];

        foreach ($this->modelFinder->find($options['model_paths'] ?? []) as $class) {
            $info = $this->modelAnalyzer->analyze($class);

            // With single-table inheritance several models share a table; the first one found describes it.
            if ($info !== null && $schema->has($info->table)) {
                $models[$info->table] ??= $info;
            }
        }

        ksort($models);

        $this->notices = [];
        $graph = $this->withMorphTargets(SchemaGraph::build($schema, $models), $connection, (array) ($options['morph_targets'] ?? []));

        return new ProjectAnalysis(
            schema: $schema,
            models: $models,
            enums: $this->enumAnalyzer->analyze($schema, $models),
            graph: $graph,
            migrations: $migrations,
            warnings: [...$this->schemaReader->warnings(), ...$this->modelAnalyzer->warnings()],
            factories: $this->factoryAnalyzer->analyze($models),
            notices: $this->notices,
        );
    }

    /**
     * Polymorphic relations whose owning models don't declare morphMany/morphOne still
     * get targets from configuration ("morph_targets") or from the types already stored.
     *
     * @param  array<string, list<string>>  $configured  "table.name" => [model class or morph alias]
     */
    private function withMorphTargets(SchemaGraph $graph, Connection $connection, array $configured): SchemaGraph
    {
        $targets = [];
        $slots = [];

        foreach ($graph->morphSlots() as $slot) {
            $slots["{$slot->table}.{$slot->name}"] = true;
        }

        foreach (array_keys($configured) as $key) {
            if (! isset($slots[$key])) {
                $this->notices[] = "Ignored morph_targets for [{$key}]: it isn't a polymorphic relation "
                    .'(its _type column has fixed values, or its _id column is a regular reference). Remove it from config/realseed.php.';
            }
        }

        foreach ($graph->morphSlots() as $slot) {
            $key = "{$slot->table}.{$slot->name}";
            $types = $configured[$key] ?? [];

            if ($types === [] && $slot->targets === []) {
                try {
                    $types = $connection->table($slot->table)->whereNotNull($slot->typeColumn)->distinct()->limit(20)->pluck($slot->typeColumn)->all();
                } catch (Throwable) {
                    $types = [];
                }
            }

            foreach ($types as $type) {
                if (is_string($type) && ($resolved = $this->resolveMorphType($type)) !== null) {
                    $targets[$key][$resolved[0]] = $resolved[1];
                }
            }
        }

        return $targets === [] ? $graph : $graph->withMorphTargets($targets);
    }

    /**
     * @return array{string, string}|null [value stored in the type column, target table]
     */
    private function resolveMorphType(string $type): ?array
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        try {
            $model = new $class;
        } catch (Throwable) {
            return null;
        }

        // Store what Eloquent itself would store: the alias when one is mapped.
        return [$model->getMorphClass(), $model->getTable()];
    }
}
