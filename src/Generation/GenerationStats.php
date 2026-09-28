<?php

namespace AISeeder\Generation;

final class GenerationStats
{
    /** @var array<string, int> */
    public array $generated = [];

    /** @var array<string, int> Rows dropped after repeated unique-constraint collisions. */
    public array $skipped = [];

    public int $relationships = 0;

    public int $backfilled = 0;

    /** @var list<string> Verification checks that passed. */
    public array $checks = [];

    public float $seconds = 0.0;

    public function total(): int
    {
        return array_sum($this->generated);
    }
}
