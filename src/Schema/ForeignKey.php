<?php

namespace Ayangzy\RealSeed\Schema;

final readonly class ForeignKey
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $foreignColumns
     */
    public function __construct(
        public array $columns,
        public string $foreignTable,
        public array $foreignColumns,
        public ?string $onDelete = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $foreignKey  A row from Schema::getForeignKeys().
     */
    public static function fromArray(array $foreignKey): self
    {
        return new self(
            columns: array_values($foreignKey['columns']),
            foreignTable: $foreignKey['foreign_table'],
            foreignColumns: array_values($foreignKey['foreign_columns']),
            onDelete: isset($foreignKey['on_delete']) ? strtolower($foreignKey['on_delete']) : null,
        );
    }
}
