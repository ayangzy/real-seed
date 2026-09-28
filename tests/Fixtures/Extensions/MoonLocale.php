<?php

namespace AISeeder\Tests\Fixtures\Extensions;

use AISeeder\Extension\FieldContext;
use AISeeder\Locale\FakerLocale;
use AISeeder\Semantics\Semantic;

class MoonLocale extends FakerLocale
{
    public function __construct()
    {
        parent::__construct('en_US', 'MNC');
    }

    public function value(string $semantic, FieldContext $context): mixed
    {
        return $semantic === Semantic::CITY ? 'Tranquility Base' : null;
    }
}
