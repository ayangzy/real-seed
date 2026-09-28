<?php

namespace AISeeder\Tests\Fixtures\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';
}
