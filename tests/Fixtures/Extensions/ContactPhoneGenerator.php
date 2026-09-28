<?php

namespace AISeeder\Tests\Fixtures\Extensions;

use AISeeder\Extension\FieldContext;
use AISeeder\Extension\FieldGenerator;

class ContactPhoneGenerator implements FieldGenerator
{
    public function generate(FieldContext $context): mixed
    {
        return 'contact-specific';
    }
}
