<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order as admins see it: everything the customer sees (from the order's snapshots), plus the review
 * reason, the customer's reference_id and every payment attempt.
 *
 * @property Order $resource
 */
class AdminOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $currentPayment = $order->latestPayment;

        return [
            /** Internal database id: shown for reference only; URLs take order_number. */
            'id' => $order->id,
            'order_number' => $order->order_number,
            /** pending (checkout waiting for payment), confirmed, processing, completed or cancelled. */
            'status' => $order->status,
            /** The newest payment attempt's status. */
            'payment_status' => $order->latestPayment?->status,
            /** The order date; null while pending or when the payment never went through. */
            'placed_at' => $order->placed_at?->toIso8601String(),
            /** When the customer pressed Pay. */
            'created_at' => $order->created_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $order->cancellation_reason,
            /** Why this order needs an admin's attention (e.g. paid when stock was short); null when nothing to check. */
            'review_reason' => $order->review_reason,
            /** Rupees as strings. total_amount = subtotal - discount_amount + shipping_amount; prices include GST. */
            'fare_breakup' => [
                /** ISO 4217 code all amounts on this order are in (e.g. INR), and its display symbol (₹). */
                ...Currency::fields($order->currency),
                'subtotal' => $order->subtotal,
                'discount_amount' => $order->discount_amount,
                'shipping_amount' => $order->shipping_amount,
                'total_amount' => $order->total_amount,
            ],
            /** As at checkout. reference_id is the customer's current account (also for a deleted one). */
            'customer' => [
                'reference_id' => $order->customerProfile?->user?->reference_id,
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
            ],
            /** The delivery address as it was at checkout. */
            'shipping_address' => [
                'recipient_name' => $order->recipient_name,
                'phone' => $order->recipient_phone,
                'address_line_1' => $order->address_line_1,
                'address_line_2' => $order->address_line_2,
                'city' => $order->city,
                'state' => $order->state,
                'postal_code' => $order->postal_code,
                'country' => $order->country,
            ],
            'tracking' => [
                'provider' => $order->tracking_provider,
                'number' => $order->tracking_number,
            ],
            'items' => OrderItemResource::collection($order->items),
            /** The current (newest) payment attempt: show this one. Its status is payment_status. Null only if none. */
            'payment' => $currentPayment === null ? null : new AdminPaymentResource($currentPayment),
            /** Earlier attempts (failed, cancelled or replaced), newest first; excludes `payment`. Empty when there were none. */
            'payment_history' => AdminPaymentResource::collection(
                $order->payments->reject(fn ($payment) => $payment->is($currentPayment))->sortByDesc('id')->values(),
            ),
        ];
    }
}
