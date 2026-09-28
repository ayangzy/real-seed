<?php

namespace AISeeder\Planning;

final readonly class TablePlan
{
    /**
     * @param  array<string, FieldPlan>  $fields  Keyed by column name.
     */
    public function __construct(
        public string $table,
        public int $count,
        public array $fields,
    ) {
    }

    public function field(string $column): ?FieldPlan
    {
        return $this->fields[$column] ?? null;
    }

    public function withCount(int $count): self
    {
        return new self($this->table, $count, $this->fields);
    }

    public function withFields(array $fields): self
    {
        return new self($this->table, $this->count, $fields);
    }

    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'fields' => array_map(fn (FieldPlan $field) => $field->toArray(), $this->fields),
        ];
    }

    public static function fromArray(string $table, array $data): self
    {
        return new self(
            $table,
            (int) ($data['count'] ?? 0),
            array_map(fn (array $field) => FieldPlan::fromArray($field), (array) ($data['fields'] ?? [])),
        );
    }
}
