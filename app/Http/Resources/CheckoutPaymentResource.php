<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where to pay: the pending order's number and total, and the Razorpay page to redirect to.
 *
 * @property Payment $resource
 */
class CheckoutPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** Keep it: Razorpay returns the customer to the result page with ?order=<order_number>. */
            'order_number' => $this->resource->order->order_number,
            'payment_number' => $this->resource->payment_number,
            /** Rupees as a string; prices include GST and there is no shipping fare yet. */
            'total_amount' => $this->resource->amount,
            'currency' => $this->resource->currency,
            /** Redirect the browser here (Razorpay's payment page). */
            'payment_url' => $this->resource->payment_url,
            /** The link stops working after this; press Pay again for a new one. */
            'expires_at' => $this->resource->expires_at?->toIso8601String(),
        ];
    }
}
