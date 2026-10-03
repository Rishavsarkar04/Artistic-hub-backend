<?php

namespace App\Data;

/** A payment link Razorpay created: its id, the page to send the customer to, and the raw response. */
final readonly class CreatedPaymentLink
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public string $id,
        public string $url,
        public array $response,
    ) {}
}
