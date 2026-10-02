<?php

namespace App\Enums;

/** customer_addresses.label. */
enum AddressLabel: string
{
    case Home = 'home';
    case Work = 'work';
    case Other = 'other';
}
