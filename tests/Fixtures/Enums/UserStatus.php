<?php

namespace Ayangzy\RealSeed\Tests\Fixtures\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';
}
