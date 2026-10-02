<?php

namespace App\Enums;

/** The admin product list's status filter, over products.is_active. */
enum ProductStatusFilter: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
