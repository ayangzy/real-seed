<?php

namespace AISeeder\Tests\Fixtures\Extensions;

use AISeeder\Extension\ReferenceContext;
use AISeeder\Extension\ReferencePicker;

class NoAssigneePicker implements ReferencePicker
{
    public function pick(ReferenceContext $context): int|string|null
    {
        return null;
    }
}
