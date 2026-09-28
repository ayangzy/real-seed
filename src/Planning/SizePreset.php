<?php

namespace AISeeder\Planning;

use InvalidArgumentException;

/**
 * Dataset size presets. Counts are derived from the graph, not applied per table:
 * root tables get "root" rows, each level of children fans out from its parents,
 * and small reference tables (tags, roles, categories) stay small.
 */
final readonly class SizePreset
{
    public function __construct(
        public string $name,
        public int $root,
        public int $lookup,
        public int $fanout,
        public int $cap,
        public int $historyMonths,
    ) {
    }

    public static function named(string $name): self
    {
        return match ($name) {
            'small' => new self('small', root: 3, lookup: 5, fanout: 3, cap: 150, historyMonths: 6),
            'medium' => new self('medium', root: 10, lookup: 8, fanout: 5, cap: 2500, historyMonths: 12),
            'large' => new self('large', root: 40, lookup: 12, fanout: 8, cap: 50000, historyMonths: 24),
            default => throw new InvalidArgumentException("Unknown size [{$name}]. Use small, medium, or large."),
        };
    }
}
