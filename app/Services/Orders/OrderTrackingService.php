<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only change admins can make to an order: its tracking provider and number (BE-ORDER-03). Saving
 * tracking completes the order (decided 2026-10-03): "completed" means fulfilled by the shop, i.e. handed
 * to the courier, not delivered. There is no delivery tracking; a separate delivered status may come later.
 */
final class OrderTrackingService
{
    /**
     * Saves (or corrects) the tracking details of a placed order, records which admin did it and when, and
     * marks the order completed. completed_at is set the first time; a correction keeps it.
     *
     * @throws ValidationException `order` when the order was never placed (pending or failed checkout) or is cancelled
     */
    public function updateTracking(Order $order, string $provider, string $number, User $admin): Order
    {
        DB::transaction(function () use ($order, $provider, $number, $admin) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($locked->placed_at === null || $locked->status === OrderStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'order' => 'Tracking can only be added to a placed order that is not cancelled.',
                ]);
            }

            $locked->forceFill([
                'tracking_provider' => $provider,
                'tracking_number' => $number,
                'tracking_updated_at' => now(),
                'tracking_updated_by' => $admin->id,
                'status' => OrderStatus::Completed,
                'completed_at' => $locked->completed_at ?? now(),
            ])->save();
        });

        return $order->refresh();
    }
}
