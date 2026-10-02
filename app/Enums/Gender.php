<?php

namespace App\Enums;

/** customer_profiles.gender (optional). */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';
}
