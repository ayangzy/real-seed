<?php

namespace Ayangzy\RealSeed\Tests\Fixtures\Extensions;

use Ayangzy\RealSeed\Extension\ReferenceContext;
use Ayangzy\RealSeed\Extension\ReferencePicker;

class FirstMemberPicker implements ReferencePicker
{
    public function pick(ReferenceContext $context): int|string|null
    {
        return min($context->candidates);
    }
}
