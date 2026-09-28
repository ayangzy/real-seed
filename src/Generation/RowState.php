<?php

namespace AISeeder\Generation;

use Carbon\CarbonInterface;

/**
 * The row being built, so later columns can derive from earlier ones.
 */
final class RowState
{
    /** @var array<string, mixed> column => value */
    public array $values = [];

    /** @var array<string, int> column => Unix timestamp, for temporal columns */
    public array $timestamps = [];

    /** @var array<string, string> semantic => column */
    private array $semantics = [];

    public function __construct(
        public readonly string $table,
        public readonly int $time,
        public readonly int $sequence,
    ) {
    }

    public function set(string $column, string $semantic, mixed $value): void
    {
        $this->values[$column] = $value;
        $this->semantics[$semantic] ??= $column;

        if ($value instanceof CarbonInterface) {
            $this->timestamps[$column] = $value->getTimestamp();
        }
    }

    public function valueFor(string $semantic): ?string
    {
        $column = $this->semantics[$semantic] ?? null;
        $value = $column === null ? null : ($this->values[$column] ?? null);

        return is_scalar($value) ? (string) $value : null;
    }

    public function latestTimestamp(): ?int
    {
        return $this->timestamps === [] ? null : max($this->timestamps);
    }
}
