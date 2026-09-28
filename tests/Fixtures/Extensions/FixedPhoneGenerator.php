<?php

namespace AISeeder\Tests\Fixtures\Extensions;

use AISeeder\Extension\FieldContext;
use AISeeder\Extension\FieldGenerator;

class FixedPhoneGenerator implements FieldGenerator
{
    public function generate(FieldContext $context): mixed
    {
        return '+1 555 0100 '.$context->random->int(10, 99);
    }
}
