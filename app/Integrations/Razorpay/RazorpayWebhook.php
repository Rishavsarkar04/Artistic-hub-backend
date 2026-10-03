<?php

namespace App\Integrations\Razorpay;

use App\Data\PaymentLinkEvent;
use App\Enums\PaymentLinkEventType;
use App\Exceptions\Checkout\InvalidWebhookSignature;
use Illuminate\Support\Facades\Log;

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
     * An event we handle that is missing a field we need is logged as a warning before it is ignored:
     * the webhook is still answered 200, so Razorpay will not retry it, and a paid link would otherwise
     * leave its order pending (then cancelled as expired) with no trace of why.
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

        if ($type === null) {
            return null;
        }

        $link = data_get($body, 'payload.payment_link.entity');

        if (! is_array($link) || ! isset($link['id'])) {
            $this->warnMalformed($body, 'payload.payment_link.entity.id');

            return null;
        }

        if ($type !== PaymentLinkEventType::Paid) {
            return new PaymentLinkEvent($type, $link['id'], $link['reference_id'] ?? null);
        }

        $payment = data_get($body, 'payload.payment.entity');

        if (! is_array($payment) || ! isset($payment['id'])) {
            $this->warnMalformed($body, 'payload.payment.entity.id', $link);

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

    /**
     * Logs an event we handle that arrived without $missingField, with the ids needed to find it in the
     * Razorpay dashboard.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>|null  $link
     */
    private function warnMalformed(array $body, string $missingField, ?array $link = null): void
    {
        Log::warning('Razorpay webhook ignored: missing '.$missingField, [
            'event' => $body['event'],
            'event_id' => $body['id'] ?? null,
            'link_id' => $link['id'] ?? null,
            'reference_id' => $link['reference_id'] ?? null,
        ]);
    }
}
