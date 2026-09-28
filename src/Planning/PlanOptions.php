<?php

namespace Ayangzy\RealSeed\Planning;

use Carbon\CarbonImmutable;

final readonly class PlanOptions
{
    /**
     * @param  list<string>|null  $only
     * @param  list<string>  $except
     * @param  array<string, int>  $existingCounts  Rows already in each table.
     * @param  list<string>  $protected  Tables holding real data: never generated into, never deleted.
     */
    public function __construct(
        public int $seed,
        public string $locale = 'en_US',
        public string $size = 'medium',
        public ?int $count = null,
        public ?array $only = null,
        public array $except = [],
        public array $existingCounts = [],
        public ?CarbonImmutable $now = null,
        public ?string $scenario = null,
        public bool $fresh = false,
        public array $protected = [],
    ) {
    }

    public function isProtected(string $table): bool
    {
        return in_array($table, $this->protected, true);
    }

    /**
     * Rows that will still exist when generation starts: none under --fresh, except in
     * protected tables, which are never deleted.
     */
    public function existing(string $table): int
    {
        return $this->fresh && ! $this->isProtected($table) ? 0 : ($this->existingCounts[$table] ?? 0);
    }
}
