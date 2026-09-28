<?php

namespace AISeeder\Analysis;

use AISeeder\Schema\DatabaseSchema;
use BackedEnum;

/**
 * Collects the value sets of enum-like columns from PHP enum casts and from
 * database-level enum types / check constraints.
 */
final class EnumAnalyzer
{
    /**
     * @param  array<string, ModelInfo>  $models  Keyed by table name.
     * @return array<string, EnumInfo> Keyed by "table.column".
     */
    public function analyze(DatabaseSchema $schema, array $models): array
    {
        $enums = [];

        foreach ($models as $table => $model) {
            foreach ($model->casts as $column => $cast) {
                if (! $schema->table($table)?->hasColumn($column) || ! enum_exists($cast)) {
                    continue;
                }

                $cases = $cast::cases();

                $enums["{$table}.{$column}"] = new EnumInfo(
                    table: $table,
                    column: $column,
                    class: $cast,
                    values: array_map(fn ($case) => $case instanceof BackedEnum ? $case->value : $case->name, $cases),
                    labels: array_map(fn ($case) => $case->name, $cases),
                );
            }
        }

        foreach ($schema->tables as $table) {
            foreach ($table->columns as $column) {
                if ($column->allowedValues === null || isset($enums["{$table->name}.{$column->name}"])) {
                    continue;
                }

                $enums["{$table->name}.{$column->name}"] = new EnumInfo(
                    table: $table->name,
                    column: $column->name,
                    class: null,
                    values: $column->allowedValues,
                    labels: $column->allowedValues,
                );
            }
        }

        ksort($enums);

        return $enums;
    }
}
