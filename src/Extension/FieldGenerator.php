<?php

namespace Ayangzy\RealSeed\Extension;

/**
 * Generates one column's values. Register in config/realseed.php under "generators"
 * by "table.column", "*.column", or "semantic:<semantic>" (most specific wins).
 */
interface FieldGenerator
{
    public function generate(FieldContext $context): mixed;
}
