<?php

namespace Ayangzy\RealSeed\Validation;

use RuntimeException;

final class GenerationException extends RuntimeException
{
    public function __construct(
        public readonly string $table,
        public readonly ?string $column,
        string $problem,
    ) {
        parent::__construct($column === null ? "[{$table}] {$problem}." : "[{$table}.{$column}] {$problem}.");
    }
}
