<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the admin order list. Customer and city come from the order's snapshot (as they were at
 * checkout), not from the customer's current profile.
 *
 * @property Order $resource
 */
class AdminOrderSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        return [
            /** Internal database id: shown for reference only; URLs take order_number. */
            'id' => $order->id,
            'order_number' => $order->order_number,
            /** confirmed, processing, completed or cancelled. */
            'status' => $order->status,
            /** The newest payment attempt's status. */
            'payment_status' => $order->latestPayment?->status,
            /** The order date. */
            'placed_at' => $order->placed_at?->toIso8601String(),
            'customer' => [
                /** The customer's user reference_id: pass it as customer_id to show only their orders. */
                'reference_id' => $order->customerProfile?->user?->reference_id,
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
            ],
            /** Delivery city. */
            'city' => $order->city,
            /** Total units across all lines. */
            'item_count' => (int) $order->item_count,
            /** Rupees as a string. */
            'total_amount' => $order->total_amount,
            /** ISO 4217 (e.g. INR) and its display symbol (₹). */
            ...Currency::fields($order->currency),
            /** True when the order has a review_reason, e.g. it was paid when stock was short. */
            'needs_review' => $order->review_reason !== null,
        ];
    }
}
