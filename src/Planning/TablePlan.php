<?php

namespace AISeeder\Planning;

/**
 * Recognised table options:
 *  - factory_states: state name => weight, applied when rows come from the model's factory
 */
final readonly class TablePlan
{
    /**
     * @param  array<string, FieldPlan>  $fields  Keyed by column name.
     */
    public function __construct(
        public string $table,
        public int $count,
        public array $fields,
        public array $options = [],
    ) {
    }

    public function field(string $column): ?FieldPlan
    {
        return $this->fields[$column] ?? null;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function withCount(int $count): self
    {
        return new self($this->table, $count, $this->fields, $this->options);
    }

    public function withFields(array $fields): self
    {
        return new self($this->table, $this->count, $fields, $this->options);
    }

    public function withOptions(array $options): self
    {
        return new self($this->table, $this->count, $this->fields, array_replace($this->options, $options));
    }

    public function toArray(): array
    {
        return array_filter([
            'count' => $this->count,
            'fields' => array_map(fn (FieldPlan $field) => $field->toArray(), $this->fields),
            'options' => $this->options,
        ], fn ($value) => $value !== []);
    }

    public static function fromArray(string $table, array $data): self
    {
        return new self(
            $table,
            (int) ($data['count'] ?? 0),
            array_map(fn (array $field) => FieldPlan::fromArray($field), (array) ($data['fields'] ?? [])),
            (array) ($data['options'] ?? []),
        );
    }
}
