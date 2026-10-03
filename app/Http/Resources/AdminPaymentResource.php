<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payment attempt as admins see it, with the provider's ids for looking it up in the Razorpay dashboard.
 *
 * @property Payment $resource
 */
class AdminPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;

        return [
            'payment_number' => $payment->payment_number,
            /** razorpay */
            'provider' => $payment->provider,
            /** pending, processing, paid, failed, cancelled, refunded. */
            'status' => $payment->status,
            /** Null until paid. */
            'method' => $payment->method,
            'amount' => $payment->amount,
            /** ISO 4217 (e.g. INR) and its display symbol (₹). */
            ...Currency::fields($payment->currency),
            /** Razorpay payment id (pay_…), once paid. */
            'transaction_id' => $payment->transaction_id,
            /** Razorpay payment link id (plink_…); null when the link could not be created. */
            'payment_link_id' => $payment->payment_session_id,
            'created_at' => $payment->created_at?->toIso8601String(),
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'failed_at' => $payment->failed_at?->toIso8601String(),
            /** Why the payment could not be started, e.g. Razorpay refused the request. */
            'failure_reason' => $payment->failure_reason,
        ];
    }
}
