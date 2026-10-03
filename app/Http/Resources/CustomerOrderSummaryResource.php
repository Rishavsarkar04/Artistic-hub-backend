<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the customer's order list: enough for a card; GET /customer/orders/{order_number} has the rest.
 *
 * @property Order $resource
 */
class CustomerOrderSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        /** @var OrderItem|null $firstItem */
        $firstItem = $order->items->first();
        $photoPath = $firstItem?->variant_photo_path;

        return [
            /** Internal database id: shown for reference only; URLs take order_number. */
            'id' => $order->id,
            'order_number' => $order->order_number,
            /** confirmed, processing, completed or cancelled (pending orders are not listed). */
            'status' => $order->status,
            /** The newest payment attempt's status (paid for placed orders; refunded later). */
            'payment_status' => $order->latestPayment?->status,
            /** The order date. */
            'placed_at' => $order->placed_at?->toIso8601String(),
            /** Rupees as a string. */
            'total_amount' => $order->total_amount,
            /** Total units across all lines. */
            'item_count' => (int) $order->items->sum('quantity'),
            /** The first line, for the card's picture and title ("Amber & Sandalwood and 2 more"). */
            'first_item' => $firstItem === null ? null : [
                'product_name' => $firstItem->product_name,
                'variant_name' => $firstItem->variant_name,
                'photo_url' => $photoPath === null ? null : app(MediaStorageService::class)->url($photoPath),
            ],
            /** Number of lines; the card can say "and N more" with line_count - 1. */
            'line_count' => $order->items->count(),
        ];
    }
}
