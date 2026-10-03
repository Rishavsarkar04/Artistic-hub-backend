<?php

namespace App\Exceptions\Checkout;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** The webhook was not signed with our webhook secret. Rendered as 400; nothing is changed. */
class InvalidWebhookSignature extends BadRequestHttpException
{
    public function __construct()
    {
        parent::__construct('Invalid webhook signature.');
    }
}
