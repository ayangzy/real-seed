<?php

namespace Ayangzy\RealSeed\Tests\Fixtures\Extensions;

use Ayangzy\RealSeed\Extension\FieldContext;
use Ayangzy\RealSeed\Extension\FieldGenerator;

class FixedPhoneGenerator implements FieldGenerator
{
    public function generate(FieldContext $context): mixed
    {
        return '+1 555 0100 '.$context->random->int(10, 99);
    }
}
