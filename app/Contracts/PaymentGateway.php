<?php

namespace App\Contracts;

use App\Data\CreatedPaymentLink;
use App\Data\PaymentLinkRequest;
use App\Exceptions\Checkout\PaymentGatewayUnavailable;
use App\Integrations\Razorpay\RazorpayGateway;
use Illuminate\Container\Attributes\Bind;

/**
 * The payment provider, as the application sees it. Services depend on this, never on a provider class.
 * #[Bind] tells the container which implementation to inject, so no service-provider binding is needed;
 * tests swap it with $this->mock(PaymentGateway::class) or rely on Http::fake().
 */
#[Bind(RazorpayGateway::class)]
interface PaymentGateway
{
    /**
     * Creates a hosted payment link for exactly the requested amount.
     *
     * @throws PaymentGatewayUnavailable when the provider cannot be reached or refuses the request
     */
    public function createPaymentLink(PaymentLinkRequest $request): CreatedPaymentLink;

    /**
     * Cancels a payment link so it can no longer be paid. Fails when it was already paid or expired.
     *
     * @throws PaymentGatewayUnavailable when the provider cannot be reached or refuses the request
     */
    public function cancelPaymentLink(string $linkId): void;
}
