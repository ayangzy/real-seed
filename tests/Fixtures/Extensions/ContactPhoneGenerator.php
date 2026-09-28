<?php

namespace Ayangzy\RealSeed\Tests\Fixtures\Extensions;

use Ayangzy\RealSeed\Extension\FieldContext;
use Ayangzy\RealSeed\Extension\FieldGenerator;

class ContactPhoneGenerator implements FieldGenerator
{
    public function generate(FieldContext $context): mixed
    {
        return 'contact-specific';
    }
}
