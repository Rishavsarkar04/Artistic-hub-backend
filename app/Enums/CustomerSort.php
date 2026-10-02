<?php

namespace App\Enums;

/** Sort orders for the admin customer list. Order-based sorts arrive with the orders tables. */
enum CustomerSort: string
{
    case Newest = 'newest';
    case Oldest = 'oldest';
    case Name = 'name';
}
