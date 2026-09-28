<?php

namespace AISeeder\Tests\Fixtures\Extensions;

use AISeeder\Extension\ReferenceContext;
use AISeeder\Extension\ReferencePicker;

class BogusOwnerPicker implements ReferencePicker
{
    public function pick(ReferenceContext $context): int|string|null
    {
        return 424242; // not a candidate: must be ignored
    }
}
