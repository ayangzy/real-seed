<?php

namespace AISeeder\Analysis;

use AISeeder\Graph\SchemaGraph;
use AISeeder\Schema\SchemaReader;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;

/**
 * Runs the analysis pipeline: migrations -> schema -> models -> enums -> factories -> graph.
 */
final class ProjectAnalyzer
{
    public function __construct(
        private readonly SchemaReader $schemaReader,
        private readonly ModelFinder $modelFinder,
        private readonly ModelAnalyzer $modelAnalyzer,
        private readonly EnumAnalyzer $enumAnalyzer,
        private readonly FactoryAnalyzer $factoryAnalyzer,
    ) {
    }

    /**
     * @param  array{model_paths?: list<string>, migration_paths?: list<string>, excluded_tables?: list<string>, excluded_columns?: list<string>}  $options
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

        return new ProjectAnalysis(
            schema: $schema,
            models: $models,
            enums: $this->enumAnalyzer->analyze($schema, $models),
            graph: SchemaGraph::build($schema, $models),
            migrations: $migrations,
            warnings: [...$this->schemaReader->warnings(), ...$this->modelAnalyzer->warnings()],
            factories: $this->factoryAnalyzer->analyze($models),
        );
    }
}
