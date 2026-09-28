<?php

namespace AISeeder\Validation;

use AISeeder\Schema\TableSchema;

/**
 * Last line of defence before insert: every serialized row is checked against the
 * discovered schema, whatever produced the plan (heuristics, AI, or configuration).
 */
final class GenerationValidator
{
    /**
     * @param  array<string, mixed>  $row
     *
     * @throws GenerationException
     */
    public function validate(TableSchema $table, array $row): void
    {
        foreach ($row as $column => $value) {
            $schema = $table->column($column);

            if ($schema === null) {
                throw new GenerationException($table->name, $column, 'is not a column of this table');
            }

            if ($value === null) {
                if (! $schema->nullable && ! $schema->hasDefault() && ! $schema->autoIncrement) {
                    throw new GenerationException($table->name, $column, 'is required but no value was generated');
                }

                continue;
            }

            if ($schema->allowedValues !== null && ! in_array((string) (is_bool($value) ? (int) $value : $value), array_map('strval', $schema->allowedValues), true)) {
                throw new GenerationException($table->name, $column, sprintf('value [%s] is not one of [%s]', $value, implode(', ', $schema->allowedValues)));
            }

            if (is_string($value) && ($max = $schema->maxLength()) !== null && mb_strlen($value) > $max) {
                throw new GenerationException($table->name, $column, "value is longer than {$max} characters");
            }
        }

        foreach ($table->columns as $name => $schema) {
            if (! array_key_exists($name, $row) && $schema->isRequired()) {
                throw new GenerationException($table->name, $name, 'is required but was not generated');
            }
        }

        $created = $row['created_at'] ?? null;
        $updated = $row['updated_at'] ?? null;

        if (is_string($created) && is_string($updated) && $created > $updated) {
            throw new GenerationException($table->name, 'updated_at', 'is earlier than created_at');
        }
    }
}
