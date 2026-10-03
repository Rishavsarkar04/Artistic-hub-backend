<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Cancels unpaid payments: the one rule for a link that can no longer be paid, whether it expired, was
 * cancelled in the Razorpay dashboard, or was replaced by a newer checkout.
 */
final class PaymentCancellationService
{
    /**
     * Marks the payment cancelled, and its order cancelled with $reason if the order is still pending.
     * Does nothing when the payment is no longer pending (already paid, failed or cancelled), so it is safe
     * to call twice and never undoes a payment that arrived first.
     */
    public function cancelPending(Payment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($payment->status !== PaymentStatus::Pending) {
                return;
            }

            $payment->forceFill(['status' => PaymentStatus::Cancelled])->save();

            $order = Order::whereKey($payment->order_id)->lockForUpdate()->first();
            if ($order->status === OrderStatus::Pending) {
                $order->forceFill([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason,
                ])->save();
            }
        });
    }
}
