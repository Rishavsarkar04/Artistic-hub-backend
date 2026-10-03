<?php

namespace App\Integrations\Razorpay;

use App\Data\PaymentLinkEvent;
use App\Enums\PaymentLinkEventType;
use App\Exceptions\Checkout\InvalidWebhookSignature;

/**
 * Verifies Razorpay webhooks and turns the payment link events we handle into PaymentLinkEvent.
 * Razorpay signs the raw body with HMAC-SHA256 using the webhook secret set in its dashboard
 * (RAZORPAY_WEBHOOK_SECRET) and sends it in the X-Razorpay-Signature header.
 *
 * @see https://razorpay.com/docs/webhooks/validate-test/
 * @see https://razorpay.com/docs/webhooks/payloads/payment-links/
 */
final class RazorpayWebhook
{
    public const SIGNATURE_HEADER = 'X-Razorpay-Signature';

    /** @throws InvalidWebhookSignature */
    public function verify(string $rawBody, ?string $signature): void
    {
        $secret = (string) config('services.razorpay.webhook_secret');

        if ($secret === '' || $signature === null || $signature === '') {
            throw new InvalidWebhookSignature;
        }

        if (! hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
            throw new InvalidWebhookSignature;
        }
    }

    /**
     * The event, or null for events we do not handle (Razorpay may send others the dashboard enables).
     *
     * @param  array<string, mixed>  $body  the verified, decoded body
     */
    public function toEvent(array $body): ?PaymentLinkEvent
    {
        $type = match ($body['event'] ?? null) {
            'payment_link.paid' => PaymentLinkEventType::Paid,
            'payment_link.expired' => PaymentLinkEventType::Expired,
            'payment_link.cancelled' => PaymentLinkEventType::Cancelled,
            default => null,
        };
        $link = data_get($body, 'payload.payment_link.entity');

        if ($type === null || ! is_array($link) || ! isset($link['id'])) {
            return null;
        }

        $payment = data_get($body, 'payload.payment.entity');

        if ($type !== PaymentLinkEventType::Paid) {
            return new PaymentLinkEvent($type, $link['id'], $link['reference_id'] ?? null);
        }

        if (! is_array($payment) || ! isset($payment['id'])) {
            return null;
        }

        return new PaymentLinkEvent(
            type: $type,
            linkId: $link['id'],
            referenceId: $link['reference_id'] ?? null,
            paymentId: $payment['id'],
            method: $payment['method'] ?? null,
            payment: $payment,
        );
    }
}
