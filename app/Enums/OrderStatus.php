<?php

namespace App\Enums;

/**
 * orders.status. So far: pending → confirmed (verified payment), pending → cancelled (link expired, cancelled or
 * replaced, or the payment could not start), confirmed → completed (admin saves tracking).
 */
enum OrderStatus: string
{
    /** Checkout started, payment not confirmed: not a placed order. */
    case Pending = 'pending';
    /** Paid (verified by the Razorpay webhook). */
    case Confirmed = 'confirmed';
    /** Not used yet. */
    case Processing = 'processing';
    /** Fulfilled: an admin added tracking (handed to the courier). Not "delivered": there is no delivery tracking. */
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
