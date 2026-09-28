<?php

namespace Ayangzy\RealSeed\Planning;

/**
 * How one column is generated.
 *
 * Recognised options (all optional):
 *  - weights:       value => weight, for enums and morph types
 *  - true_rate:     probability of true for booleans
 *  - null_rate:     probability of null for nullable columns
 *  - present_when:  [column => [values]], the value is null unless the row matches
 *  - min / max:     numeric bounds
 *  - samples:       realistic example values to draw from (usually supplied by the AI)
 *  - selection:     "skewed" | "uniform", how references pick parent rows
 *  - scope:         ancestor table references must share, or false to pick from all rows
 *  - value:         a fixed value
 */
final readonly class FieldPlan
{
    public function __construct(
        public string $semantic,
        public array $options = [],
    ) {
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function with(array $options): self
    {
        return new self($this->semantic, array_replace($this->options, $options));
    }

    public function toArray(): array
    {
        return array_filter(['semantic' => $this->semantic, 'options' => $this->options], fn ($v) => $v !== []);
    }

    public static function fromArray(array $data): self
    {
        return new self((string) $data['semantic'], (array) ($data['options'] ?? []));
    }
}
