<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as the customer sees it: status, the newest payment's status, snapshots and totals.
 *
 * @property Order $resource
 */
class CustomerOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $payment = $order->latestPayment;

        return [
            /** Internal database id: shown for reference only; URLs take order_number. */
            'id' => $order->id,
            'order_number' => $order->order_number,
            /** pending until the payment is confirmed, then confirmed, processing, completed; or cancelled. */
            'status' => $order->status,
            /** Null while pending: the order date, set when the payment is confirmed. */
            'placed_at' => $order->placed_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            /** When tracking was first added (the order was handed to the courier); null until then. */
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $order->cancellation_reason,
            'payment' => $payment === null ? null : [
                'payment_number' => $payment->payment_number,
                /** The newest payment attempt's status: pending, processing, paid, failed, cancelled, refunded. */
                'status' => $payment->status,
                /** Null until paid. */
                'method' => $payment->method,
                'amount' => $payment->amount,
                ...Currency::fields($payment->currency),
                /** True while the link can still be paid: show "Complete payment" and redirect to payment_url. */
                'can_pay' => $payment->isPayable(),
                /** Only while can_pay is true. */
                'payment_url' => $payment->isPayable() ? $payment->payment_url : null,
                'expires_at' => $payment->expires_at?->toIso8601String(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ],
            /** Rupees as strings. total_amount = subtotal - discount_amount + shipping_amount; prices include GST. */
            'fare_breakup' => [
                /** ISO 4217 code all amounts on this order are in (e.g. INR), and its display symbol (₹). */
                ...Currency::fields($order->currency),
                'subtotal' => $order->subtotal,
                'discount_amount' => $order->discount_amount,
                'shipping_amount' => $order->shipping_amount,
                'total_amount' => $order->total_amount,
            ],
            'customer' => [
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
        ];
    }
}
