<?php

namespace App\Enums;

/** Sort orders for the admin order list. Dates are the order date (placed_at). */
enum OrderSort: string
{
    case Newest = 'newest';
    case Oldest = 'oldest';
    case TotalHigh = 'total_high';
    case TotalLow = 'total_low';
}
