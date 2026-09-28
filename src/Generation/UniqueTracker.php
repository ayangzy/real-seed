<?php

namespace Ayangzy\RealSeed\Generation;

use Ayangzy\RealSeed\Schema\TableSchema;
use DateTimeInterface;

/**
 * Tracks values of unique indexes (existing and generated) so collisions are caught
 * before insert. Comparison is case-insensitive, matching MySQL's default collations.
 */
final class UniqueTracker
{
    /** @var array<string, array<string, array<string, true>>> table => [index => [value => true]] */
    private array $seen = [];

    /**
     * @return list<list<string>> Column sets that must be unique, including composite primary keys.
     */
    public static function indexes(TableSchema $table, bool $includePrimaryKey): array
    {
        $indexes = $table->uniqueIndexes;

        if ($includePrimaryKey && $table->primaryKey !== []) {
            $indexes[] = $table->primaryKey;
        }

        return array_values(array_filter($indexes, fn (array $columns) => array_diff($columns, array_keys($table->columns)) === []));
    }

    /**
     * @param  list<string>  $columns
     */
    public function remember(string $table, array $columns, array $row): void
    {
        if (($key = $this->key($columns, $row)) !== null) {
            $this->seen[$table][implode(',', $columns)][$key] = true;
        }
    }

    /**
     * @param  list<string>  $columns
     */
    public function exists(string $table, array $columns, array $row): bool
    {
        $key = $this->key($columns, $row);

        return $key !== null && isset($this->seen[$table][implode(',', $columns)][$key]);
    }

    /**
     * Null values never collide (SQL treats NULLs as distinct).
     *
     * @param  list<string>  $columns
     */
    private function key(array $columns, array $row): ?string
    {
        $parts = [];

        foreach ($columns as $column) {
            $value = $row[$column] ?? null;

            if ($value === null) {
                return null;
            }

            $parts[] = match (true) {
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                $value instanceof \BackedEnum => (string) $value->value,
                is_bool($value) => $value ? '1' : '0',
                default => mb_strtolower((string) $value),
            };
        }

        return implode("\x1F", $parts);
    }
}
