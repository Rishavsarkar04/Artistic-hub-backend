<?php

namespace App\Enums;

/** The payment link webhook events we act on. */
enum PaymentLinkEventType: string
{
    case Paid = 'paid';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
