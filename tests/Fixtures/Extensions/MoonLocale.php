<?php

namespace Ayangzy\RealSeed\Tests\Fixtures\Extensions;

use Ayangzy\RealSeed\Extension\FieldContext;
use Ayangzy\RealSeed\Locale\FakerLocale;
use Ayangzy\RealSeed\Semantics\Semantic;

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
