<?php

namespace AISeeder\Extension;

use AISeeder\Generation\SeededRandom;

final readonly class ReferenceContext
{
    /**
     * @param  list<int|string>  $candidates  Keys of the parent rows that may be referenced.
     * @param  array<string, mixed>  $row  Values already chosen for the row.
     */
    public function __construct(
        public string $table,
        public string $column,
        public string $parentTable,
        public array $candidates,
        public array $row,
        public SeededRandom $random,
    ) {
    }
}
