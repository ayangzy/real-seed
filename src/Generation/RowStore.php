<?php

namespace Ayangzy\RealSeed\Generation;

/**
 * Lightweight, memory-conscious state for one table's rows (existing + generated):
 * only the columns other rows reference or are grouped by, plus each row's time.
 * Full rows are never kept; they are inserted and discarded chunk by chunk.
 */
final class RowStore
{
    public int $count = 0;

    /** @var array<string, list<mixed>> column => values by row index */
    private array $values = [];

    /** @var list<int> Unix timestamps by row index */
    private array $times = [];

    /** @var array<string, array<string, int>> column => [value => row index] */
    private array $lookups = [];

    /** @var array<string, array<string, list<int>>> column => [value => row indexes] */
    private array $groups = [];

    /**
     * @param  list<string>  $lookupColumns  Columns referenced by other tables (unique, e.g. the primary key).
     * @param  list<string>  $groupColumns  This table's foreign key columns, used to scope related picks.
     */
    public function __construct(
        public readonly string $table,
        private readonly array $lookupColumns,
        private readonly array $groupColumns,
    ) {
        foreach ([...$lookupColumns, ...$groupColumns] as $column) {
            $this->values[$column] = [];
        }
    }

    public function add(array $row, int $time): int
    {
        $index = $this->count++;
        $this->times[] = $time;

        foreach ($this->values as $column => &$values) {
            $values[] = $row[$column] ?? null;
        }
        unset($values);

        foreach ($this->lookupColumns as $column) {
            if (($row[$column] ?? null) !== null) {
                $this->lookups[$column][(string) $row[$column]] = $index;
            }
        }

        foreach ($this->groupColumns as $column) {
            if (($row[$column] ?? null) !== null) {
                $this->groups[$column][(string) $row[$column]][] = $index;
            }
        }

        return $index;
    }

    public function value(int $index, string $column): mixed
    {
        return $this->values[$column][$index] ?? null;
    }

    public function time(int $index): int
    {
        return $this->times[$index];
    }

    public function indexOf(string $column, mixed $value): ?int
    {
        return $value === null ? null : ($this->lookups[$column][(string) $value] ?? null);
    }

    /**
     * Rows whose foreign key column holds the given value.
     *
     * @return list<int>
     */
    public function group(string $column, mixed $value): array
    {
        return $value === null ? [] : ($this->groups[$column][(string) $value] ?? []);
    }

    public function tracks(string $column): bool
    {
        return array_key_exists($column, $this->values);
    }
}
