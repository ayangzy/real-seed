<?php

namespace Ayangzy\RealSeed\Generation;

final class BuiltRow
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public array $values,
        public readonly int $time,
        public readonly int $references,
    ) {
    }
}
