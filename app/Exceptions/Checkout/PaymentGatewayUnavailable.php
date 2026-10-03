<?php

namespace App\Exceptions\Checkout;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/** Razorpay could not create the payment. Rendered as 503; the customer can try again. */
class PaymentGatewayUnavailable extends ServiceUnavailableHttpException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct(null, "We couldn't start the payment. Please try again.", $previous);
    }
}
