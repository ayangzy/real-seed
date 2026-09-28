<?php

namespace Ayangzy\RealSeed\Tests\Fixtures\Extensions;

use Ayangzy\RealSeed\Extension\ReferenceContext;
use Ayangzy\RealSeed\Extension\ReferencePicker;

class BogusOwnerPicker implements ReferencePicker
{
    public function pick(ReferenceContext $context): int|string|null
    {
        return 424242; // not a candidate: must be ignored
    }
}
