<?php

namespace AISeeder\Planning;

use Carbon\CarbonImmutable;

final readonly class PlanOptions
{
    /**
     * @param  list<string>|null  $only
     * @param  list<string>  $except
     * @param  array<string, int>  $existingCounts  Rows already in each table.
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
    ) {
    }
}
