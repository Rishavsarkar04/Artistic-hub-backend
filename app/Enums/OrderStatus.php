<?php

namespace App\Enums;

/** orders.status. Automated so far: pending → confirmed (verified payment) and pending → cancelled. */
enum OrderStatus: string
{
    /** Checkout started, payment not confirmed: not a placed order. */
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * The statuses a placed order can have, i.e. the ones order lists show and filter by. Pending is a
     * checkout still waiting for payment, never a listed order.
     *
     * @return list<self>
     */
    public static function placed(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status !== self::Pending));
    }
}
