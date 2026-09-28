<?php

namespace AISeeder\Extension;

use AISeeder\Generation\RowState;
use AISeeder\Generation\SeededRandom;
use AISeeder\Planning\FieldPlan;
use AISeeder\Schema\ColumnSchema;
use Carbon\CarbonImmutable;
use Faker\Generator as Faker;

/**
 * What a custom field generator or locale provider knows about the value it produces.
 *
 * Use $random and $faker for all randomness: both are seeded, so output stays reproducible.
 */
final readonly class FieldContext
{
    public function __construct(
        public string $table,
        public ColumnSchema $column,
        public FieldPlan $field,
        private RowState $row,
        public Faker $faker,
        public SeededRandom $random,
    ) {
    }

    /**
     * A value already generated for this row (keys, references, and earlier fields).
     */
    public function value(string $column): mixed
    {
        return $this->row->values[$column] ?? null;
    }

    /**
     * The row's value for a semantic, e.g. Semantic::STATE, if one was generated already.
     */
    public function valueFor(string $semantic): ?string
    {
        return $this->row->valueFor($semantic);
    }

    /**
     * When the row "happened" on the generated timeline.
     */
    public function time(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($this->row->time, date_default_timezone_get());
    }
}
