<?php

namespace AISeeder\Schema;

final readonly class DatabaseSchema
{
    /**
     * @param  array<string, TableSchema>  $tables  Keyed by table name.
     */
    public function __construct(public array $tables)
    {
    }

    public function has(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    public function table(string $table): ?TableSchema
    {
        return $this->tables[$table] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tables);
    }

    /**
     * A stable fingerprint of the structure, used to invalidate cached plans.
     */
    public function hash(): string
    {
        return hash('xxh128', serialize($this->tables));
    }
}
