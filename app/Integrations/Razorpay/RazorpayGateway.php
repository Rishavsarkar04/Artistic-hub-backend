<?php

namespace App\Integrations\Razorpay;

use App\Contracts\PaymentGateway;
use App\Data\CreatedPaymentLink;
use App\Data\PaymentLinkRequest;
use App\Exceptions\Checkout\PaymentGatewayUnavailable;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The Razorpay implementation of PaymentGateway and the only place that talks to Razorpay (architecture
 * guide, section 12). Uses the REST API directly through Laravel's HTTP client, so tests fake it with
 * Http::fake().
 */
final class RazorpayGateway implements PaymentGateway
{
    /**
     * Creates a payment link for the full amount (no partial payments). Razorpay shows the amount on its
     * page, so the customer confirms exactly what is charged.
     *
     * @see https://razorpay.com/docs/api/payments/payment-links/create-standard/
     *
     * @throws PaymentGatewayUnavailable
     */
    public function createPaymentLink(PaymentLinkRequest $request): CreatedPaymentLink
    {
        try {
            $response = $this->client()->post('payment_links', [
                'amount' => Money::toPaise($request->amount),
                'currency' => $request->currency,
                'accept_partial' => false,
                'reference_id' => $request->referenceId,
                'description' => $request->description,
                'customer' => [
                    'name' => $request->customerName,
                    'email' => $request->customerEmail,
                    'contact' => $request->customerPhone,
                ],
                // We send our own confirmation email; Razorpay does not notify the customer.
                'notify' => ['sms' => false, 'email' => true],
                'reminder_enable' => false,
                'expire_by' => $request->expiresAt->getTimestamp(),
                'callback_url' => $request->callbackUrl,
                'callback_method' => 'get',
                'notes' => $request->notes,
            ]);
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayUnavailable('Could not reach Razorpay: '.$exception->getMessage(), $exception);
        }

        if ($response->failed() || ! $response->json('id') || ! $response->json('short_url')) {
            throw new PaymentGatewayUnavailable(sprintf(
                'Razorpay refused the payment link (HTTP %d): %s',
                $response->status(),
                $response->json('error.description') ?? $response->body(),
            ));
        }

        return new CreatedPaymentLink(
            id: $response->json('id'),
            url: $response->json('short_url'),
            response: $response->json(),
        );
    }

    /**
     * @see https://razorpay.com/docs/api/payments/payment-links/cancel-standard/
     *
     * @throws PaymentGatewayUnavailable
     */
    public function cancelPaymentLink(string $linkId): void
    {
        try {
            $response = $this->client()->post('payment_links/'.rawurlencode($linkId).'/cancel');
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayUnavailable('Could not reach Razorpay: '.$exception->getMessage(), $exception);
        }

        if ($response->failed()) {
            throw new PaymentGatewayUnavailable(sprintf(
                'Razorpay refused to cancel payment link %s (HTTP %d): %s',
                $linkId,
                $response->status(),
                $response->json('error.description') ?? $response->body(),
            ));
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('services.razorpay.base_url'))
            ->withBasicAuth((string) config('services.razorpay.key_id'), (string) config('services.razorpay.key_secret'))
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }
}
