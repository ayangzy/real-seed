<?php

namespace AISeeder\Schema;

use AISeeder\Schema\Constraints\ConstraintReader;
use AISeeder\Schema\Constraints\PostgresConstraintReader;
use AISeeder\Schema\Constraints\SqliteConstraintReader;
use AISeeder\Schema\Constraints\SqlServerConstraintReader;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Reads the structure of the migrated database. Introspecting the live schema is
 * far more reliable than parsing migration files, which may contain conditionals,
 * raw SQL, or have been squashed into a schema dump.
 */
final class SchemaReader
{
    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  list<string>  $excludedTables  Table names or wildcard patterns.
     * @param  list<string>  $excludedColumns  "table.column" or "*.column" patterns.
     */
    public function read(Connection $connection, array $excludedTables = [], array $excludedColumns = []): DatabaseSchema
    {
        $this->warnings = [];
        $builder = $connection->getSchemaBuilder();
        $prefix = $connection->getTablePrefix();
        $constraints = $this->constraintReaderFor($connection);

        $tables = [];

        foreach ($builder->getTables($builder->getCurrentSchemaListing()) as $table) {
            $name = $prefix !== '' && str_starts_with($table['name'], $prefix)
                ? substr($table['name'], strlen($prefix))
                : $table['name'];

            if (isset($tables[$name]) || $this->matchesAny($name, $excludedTables)) {
                continue;
            }

            $tables[$name] = $this->readTable($connection, $constraints, $name, $table['comment'] ?? null, $excludedColumns);
        }

        ksort($tables);

        return new DatabaseSchema($tables);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function readTable(Connection $connection, ?ConstraintReader $constraints, string $name, ?string $comment, array $excludedColumns): TableSchema
    {
        $builder = $connection->getSchemaBuilder();

        $allowed = [];

        if ($constraints !== null) {
            try {
                $allowed = $constraints->allowedValues($connection, $name);
            } catch (Throwable $e) {
                $this->warnings[] = "Could not read check constraints for [{$name}]: {$e->getMessage()}";
            }
        }

        $columns = [];

        foreach ($builder->getColumns($name) as $column) {
            $excluded = $this->matchesAny("{$name}.{$column['name']}", $excludedColumns);

            // Required columns can't be skipped: the insert would fail.
            $schema = ColumnSchema::fromArray($column, $allowed[$column['name']] ?? null);

            if ($excluded && ! $schema->isRequired()) {
                continue;
            }

            $columns[$column['name']] = $schema;
        }

        $primaryKey = [];
        $uniqueIndexes = [];

        foreach ($builder->getIndexes($name) as $index) {
            if ($index['primary']) {
                $primaryKey = array_values($index['columns']);
            } elseif ($index['unique']) {
                $uniqueIndexes[] = array_values($index['columns']);
            }
        }

        // SQLite reports an INTEGER PRIMARY KEY rowid alias without an index entry.
        if ($primaryKey === []) {
            foreach ($columns as $column) {
                if ($column->autoIncrement) {
                    $primaryKey = [$column->name];
                }
            }
        }

        $foreignKeys = array_map(
            fn (array $fk) => ForeignKey::fromArray($fk),
            $builder->getForeignKeys($name),
        );

        return new TableSchema($name, $columns, $primaryKey, $foreignKeys, $uniqueIndexes, $comment);
    }

    private function constraintReaderFor(Connection $connection): ?ConstraintReader
    {
        return match ($connection->getDriverName()) {
            'sqlite' => new SqliteConstraintReader,
            'pgsql' => new PostgresConstraintReader,
            'sqlsrv' => new SqlServerConstraintReader,
            default => null, // MySQL/MariaDB enums are part of the column type.
        };
    }

    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $value)) {
                return true;
            }
        }

        return false;
    }
}
