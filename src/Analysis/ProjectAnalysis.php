<?php

namespace AISeeder\Analysis;

use AISeeder\Graph\SchemaGraph;
use AISeeder\Schema\DatabaseSchema;

final readonly class ProjectAnalysis
{
    /**
     * @param  array<string, ModelInfo>  $models  Keyed by table name.
     * @param  array<string, EnumInfo>  $enums  Keyed by "table.column".
     * @param  list<string>  $warnings
     */
    public function __construct(
        public DatabaseSchema $schema,
        public array $models,
        public array $enums,
        public SchemaGraph $graph,
        public MigrationStatus $migrations,
        public array $warnings = [],
    ) {
    }

    public function model(string $table): ?ModelInfo
    {
        return $this->models[$table] ?? null;
    }

    public function enum(string $table, string $column): ?EnumInfo
    {
        return $this->enums["{$table}.{$column}"] ?? null;
    }
}
