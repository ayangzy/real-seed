<?php

namespace AISeeder\Tests\Fixtures\Extensions;

use AISeeder\Extension\ReferenceContext;
use AISeeder\Extension\ReferencePicker;

class FirstMemberPicker implements ReferencePicker
{
    public function pick(ReferenceContext $context): int|string|null
    {
        return min($context->candidates);
    }
}
