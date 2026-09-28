<?php

namespace AISeeder\Generation;

use Illuminate\Support\Str;

/**
 * Pre-assigns primary keys so rows can be bulk inserted and referenced without
 * reading generated IDs back from the database.
 */
final class KeyAllocator
{
    /** @var array<string, int> */
    private array $next = [];

    public function startAfter(string $table, int $max): void
    {
        $this->next[$table] = $max + 1;
    }

    public function next(string $table, string $strategy, SeededRandom $random, int $time): int|string
    {
        return match ($strategy) {
            'uuid' => $random->uuid(),
            'ulid' => $random->ulid($time * 1000 + $random->int(0, 999)),
            'increment' => $this->increment($table),
            default => Str::slug(Str::singular($table)).'-'.$this->increment($table),
        };
    }

    private function increment(string $table): int
    {
        $this->next[$table] ??= 1;

        return $this->next[$table]++;
    }
}
