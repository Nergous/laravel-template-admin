<?php

namespace App\Enums;

/** User list filter by account state (is_active). */
enum UserStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}
