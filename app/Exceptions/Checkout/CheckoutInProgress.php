<?php

namespace App\Exceptions\Checkout;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** The same checkout is already creating its payment (e.g. Pay tapped twice). Rendered as 409. */
class CheckoutInProgress extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Your payment is being prepared. Please wait a moment and try again.');
    }
}
