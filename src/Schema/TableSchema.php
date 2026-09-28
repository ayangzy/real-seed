<?php

namespace AISeeder\Schema;

final readonly class TableSchema
{
    /**
     * @param  array<string, ColumnSchema>  $columns  Keyed by column name, in table order.
     * @param  list<string>  $primaryKey
     * @param  list<ForeignKey>  $foreignKeys
     * @param  list<list<string>>  $uniqueIndexes  Column sets, excluding the primary key.
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $primaryKey,
        public array $foreignKeys,
        public array $uniqueIndexes,
        public ?string $comment = null,
    ) {
    }

    public function hasColumn(string $column): bool
    {
        return isset($this->columns[$column]);
    }

    public function column(string $column): ?ColumnSchema
    {
        return $this->columns[$column] ?? null;
    }

    public function hasTimestamps(): bool
    {
        return $this->hasColumn('created_at') && $this->hasColumn('updated_at');
    }

    public function hasSoftDeletes(): bool
    {
        return $this->hasColumn('deleted_at');
    }

    /**
     * The single-column primary key, if the table has one.
     */
    public function singlePrimaryKey(): ?ColumnSchema
    {
        return count($this->primaryKey) === 1 ? $this->column($this->primaryKey[0]) : null;
    }

    public function isUnique(string $column): bool
    {
        if ($this->primaryKey === [$column]) {
            return true;
        }

        return in_array([$column], $this->uniqueIndexes, true);
    }

    public function withColumns(array $columns): self
    {
        return new self($this->name, $columns, $this->primaryKey, $this->foreignKeys, $this->uniqueIndexes, $this->comment);
    }
}
