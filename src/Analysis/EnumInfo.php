<?php

namespace Ayangzy\RealSeed\Analysis;

final readonly class EnumInfo
{
    /**
     * @param  list<string|int>  $values  Values as stored in the database.
     * @param  list<string>  $labels  Case names, which carry the semantic meaning (e.g. "NoShow").
     */
    public function __construct(
        public string $table,
        public string $column,
        public ?string $class,
        public array $values,
        public array $labels,
    ) {
    }
}
