<?php

namespace App\Enums;

/** orders.status. Only pending → confirmed (on a verified payment) is automated so far. */
enum OrderStatus: string
{
    /** Checkout started, payment not confirmed: not a placed order. */
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
