<?php

namespace App\Enums;

/** users.status. Only active accounts can sign in. */
enum UserStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Suspended = 'suspended';
    case Pending = 'pending';
}
