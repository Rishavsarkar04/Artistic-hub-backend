<?php

namespace App\Data;

use App\Enums\PaymentLinkEventType;

/**
 * A verified payment link webhook, in our terms. For Paid, the payment fields are set; for Expired
 * and Cancelled they are null.
 */
final readonly class PaymentLinkEvent
{
    /**
     * @param  array<string, mixed>|null  $payment  the provider's payment entity, kept on the payment row
     */
    public function __construct(
        public PaymentLinkEventType $type,
        public string $linkId,
        public ?string $referenceId,
        public ?string $paymentId = null,
        public ?string $method = null,
        public ?array $payment = null,
    ) {}
}
