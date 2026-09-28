<?php

namespace Ayangzy\RealSeed\Schema;

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

    /**
     * A driver-independent classification of the column type.
     */
    public function family(): string
    {
        $type = $this->typeName;

        return match (true) {
            $this->type === 'tinyint(1)' || in_array($type, ['bool', 'boolean', 'bit'], true) => 'boolean',
            in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'int2', 'int4', 'int8', 'serial', 'bigserial', 'smallserial'], true) => 'integer',
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8', 'double precision', 'money', 'smallmoney'], true) => 'decimal',
            in_array($type, ['date'], true) => 'date',
            in_array($type, ['datetime', 'datetime2', 'timestamp', 'timestamptz', 'datetimeoffset', 'smalldatetime'], true) => 'datetime',
            in_array($type, ['time', 'timetz'], true) => 'time',
            in_array($type, ['year'], true) => 'year',
            in_array($type, ['json', 'jsonb'], true) => 'json',
            in_array($type, ['uuid', 'uniqueidentifier'], true) || $this->type === 'char(36)' => 'uuid',
            in_array($type, ['text', 'mediumtext', 'longtext', 'tinytext', 'ntext', 'clob'], true) => 'text',
            in_array($type, ['blob', 'binary', 'varbinary', 'bytea', 'longblob', 'mediumblob', 'tinyblob', 'image'], true) => 'binary',
            default => 'string',
        };
    }

    /**
     * Maximum character length for string columns, e.g. 255 for varchar(255).
     */
    public function maxLength(): ?int
    {
        if ($this->family() !== 'string' && $this->family() !== 'uuid') {
            return null;
        }

        return preg_match('/\((\d+)\)/', $this->type, $m) ? (int) $m[1] : null;
    }

    /**
     * Precision and scale for decimal columns, e.g. [12, 2] for decimal(12,2).
     *
     * @return array{int, int}|null
     */
    public function precision(): ?array
    {
        return preg_match('/\((\d+)\s*,\s*(\d+)\)/', $this->type, $m) ? [(int) $m[1], (int) $m[2]] : null;
    }

    public function isUnsigned(): bool
    {
        return str_contains($this->type, 'unsigned');
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
