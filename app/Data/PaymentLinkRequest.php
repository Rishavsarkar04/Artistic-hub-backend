<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/** What we ask Razorpay for: a payment link for exactly this amount. */
final readonly class PaymentLinkRequest
{
    /**
     * @param  array<string, string>  $notes
     */
    public function __construct(
        public string $referenceId,
        public string $amount,
        public string $currency,
        public string $description,
        public string $customerName,
        public string $customerEmail,
        public string $customerPhone,
        public string $callbackUrl,
        public CarbonImmutable $expiresAt,
        public array $notes = [],
    ) {}
}
