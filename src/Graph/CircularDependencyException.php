<?php

namespace AISeeder\Graph;

use RuntimeException;

final class CircularDependencyException extends RuntimeException
{
    /**
     * @param  list<string>  $tables
     */
    public function __construct(public readonly array $tables)
    {
        parent::__construct(sprintf(
            'Tables [%s] reference each other through required (NOT NULL) foreign keys, so no insert order can satisfy them. '
            .'Make one of the columns nullable or exclude one of the tables.',
            implode(', ', $tables),
        ));
    }
}
