<?php

namespace Ayangzy\RealSeed\Analysis;

use Ayangzy\RealSeed\Graph\SchemaGraph;
use Ayangzy\RealSeed\Schema\DatabaseSchema;

final readonly class ProjectAnalysis
{
    /**
     * @param  array<string, ModelInfo>  $models  Keyed by table name.
     * @param  array<string, EnumInfo>  $enums  Keyed by "table.column".
     * @param  list<string>  $warnings
     * @param  array<string, FactoryInfo>  $factories  Keyed by table name.
     */
    public function __construct(
        public DatabaseSchema $schema,
        public array $models,
        public array $enums,
        public SchemaGraph $graph,
        public MigrationStatus $migrations,
        public array $warnings = [],
        public array $factories = [],
    ) {
    }

    public function factory(string $table): ?FactoryInfo
    {
        return $this->factories[$table] ?? null;
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
