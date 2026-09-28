<?php

namespace AISeeder\Schema;

final readonly class ColumnSchema
{
    /**
     * @param  list<string>|null  $allowedValues  Values permitted by an enum type or check constraint.
     */
    public function __construct(
        public string $name,
        public string $typeName,
        public string $type,
        public bool $nullable,
        public mixed $default,
        public bool $autoIncrement,
        public bool $generated = false,
        public ?string $comment = null,
        public ?array $allowedValues = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $column  A row from Schema::getColumns().
     */
    public static function fromArray(array $column, ?array $allowedValues = null): self
    {
        return new self(
            name: $column['name'],
            typeName: strtolower((string) $column['type_name']),
            type: strtolower((string) $column['type']),
            nullable: (bool) $column['nullable'],
            default: $column['default'],
            autoIncrement: (bool) $column['auto_increment'],
            generated: ! empty($column['generation']),
            comment: $column['comment'] ?? null,
            allowedValues: $allowedValues ?? self::enumValuesFromType((string) $column['type']),
        );
    }

    public function hasDefault(): bool
    {
        return $this->default !== null;
    }

    /**
     * Whether an insert must supply a value for this column.
     */
    public function isRequired(): bool
    {
        return ! $this->nullable && ! $this->hasDefault() && ! $this->autoIncrement && ! $this->generated;
    }

    public function withAllowedValues(?array $values): self
    {
        return new self(
            $this->name, $this->typeName, $this->type, $this->nullable, $this->default,
            $this->autoIncrement, $this->generated, $this->comment, $values,
        );
    }

    /**
     * MySQL/MariaDB report enums as a column type, e.g. enum('draft','published').
     *
     * @return list<string>|null
     */
    private static function enumValuesFromType(string $type): ?array
    {
        if (! preg_match('/^enum\((.*)\)$/i', $type, $matches)) {
            return null;
        }

        preg_match_all("/'((?:[^']|'')*)'/", $matches[1], $values);

        return array_map(fn (string $value) => str_replace("''", "'", $value), $values[1]);
    }
}
