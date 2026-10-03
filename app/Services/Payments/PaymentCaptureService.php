<?php

namespace App\Services\Payments;

use App\Data\PaymentLinkEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentLinkEventType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Mail\OrderConfirmed;
use App\Models\Cart;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Applies verified payment link events. Only this class confirms orders: the browser redirect never does.
 * Every event is safe to receive twice (Razorpay retries): a payment that is already paid is left alone,
 * so stock, cart and email happen exactly once.
 *
 * Lock order matches checkout and the cart (profile first), then payment, order and variants by id.
 */
final class PaymentCaptureService
{
    public function __construct(private PaymentCancellationService $paymentCancellationService) {}

    public function handle(PaymentLinkEvent $event): void
    {
        match ($event->type) {
            PaymentLinkEventType::Paid => $this->capture($event),
            PaymentLinkEventType::Expired, PaymentLinkEventType::Cancelled => $this->close($event),
        };
    }

    /**
     * The link was paid: payment paid, order confirmed and placed, stock deducted, paid quantities removed
     * from the cart, and the confirmation email queued after the commit. The order is confirmed even when
     * it had been cancelled (an older link we tried to cancel, or one that expired as it was paid): the money
     * was taken, so it is a real order. Stock short of the quantities (no reservation) is deducted down to
     * zero and the order gets a review_reason for the admin.
     */
    private function capture(PaymentLinkEvent $event): void
    {
        $payment = $this->findPayment($event);
        if ($payment === null) {
            return;
        }

        $confirmed = DB::transaction(function () use ($payment, $event) {
            CustomerProfile::withTrashed()->whereKey($payment->order->customer_profile_id)->lockForUpdate()->first();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($payment->status === PaymentStatus::Paid) {
                return null; // A repeated event: already handled.
            }

            $order = Order::whereKey($payment->order_id)->lockForUpdate()->with('items')->first();

            $shortfalls = $this->deductStock($order);
            $this->removePaidQuantitiesFromCart($order);

            $payment->forceFill([
                'status' => PaymentStatus::Paid,
                'transaction_id' => $event->paymentId,
                'method' => PaymentMethod::tryFrom((string) $event->method),
                'paid_at' => now(),
                'gateway_response' => [...($payment->gateway_response ?? []), 'payment' => $event->payment],
            ])->save();

            $order->forceFill([
                'status' => OrderStatus::Confirmed,
                'placed_at' => now(),
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'review_reason' => $shortfalls === [] ? $order->review_reason : $this->appendReason($order,
                    'Paid when stock was short: '.implode('; ', $shortfalls).'. Restock or refund.'),
            ])->save();

            return $order;
        });

        if ($confirmed !== null) {
            Mail::to($confirmed->customer_email)->queue(new OrderConfirmed($confirmed));
        }
    }

    /** The link expired or was cancelled unpaid: cancel the order if it is still waiting for it. */
    private function close(PaymentLinkEvent $event): void
    {
        $payment = $this->findPayment($event);
        if ($payment === null) {
            return;
        }

        $this->paymentCancellationService->cancelPending($payment, $event->type === PaymentLinkEventType::Expired
            ? 'The payment link expired.'
            : 'The payment link was cancelled.');
    }

    /** Our payment for the link. Links we did not create (another app on the account) are ignored. */
    private function findPayment(PaymentLinkEvent $event): ?Payment
    {
        $payment = Payment::where('payment_session_id', $event->linkId)->with('order')->first();

        if ($payment === null || ($event->referenceId !== null && $event->referenceId !== $payment->payment_number)) {
            Log::warning('Razorpay webhook for an unknown payment link', ['link_id' => $event->linkId, 'reference_id' => $event->referenceId]);

            return null;
        }

        return $payment;
    }

    /**
     * Deducts each line's quantity once, locking the variants in id order. A variant deleted since checkout
     * is skipped. Returns a line per variant that had less stock than ordered.
     *
     * @return list<string>
     */
    private function deductStock(Order $order): array
    {
        $variantIds = $order->items->pluck('product_variant_id')->filter()->unique()->sort()->values();
        $variants = ProductVariant::whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $shortfalls = [];

        foreach ($order->items as $item) {
            $variant = $variants->get($item->product_variant_id);
            if ($variant === null) {
                continue;
            }

            if ($variant->stock < $item->quantity) {
                $shortfalls[] = "{$item->product_name} ({$item->variant_name}) ordered {$item->quantity}, had {$variant->stock}";
            }

            $variant->forceFill(['stock' => max(0, $variant->stock - $item->quantity)])->save();
        }

        return $shortfalls;
    }

    /** Takes the paid quantities out of the cart; items added after checkout stay. */
    private function removePaidQuantitiesFromCart(Order $order): void
    {
        $cart = Cart::where('customer_profile_id', $order->customer_profile_id)->with('items')->first();
        if ($cart === null) {
            return;
        }

        $cartItems = $cart->items->keyBy('product_variant_id');

        $order->items->each(function (OrderItem $orderItem) use ($cartItems) {
            $cartItem = $cartItems->get($orderItem->product_variant_id);
            if ($cartItem === null) {
                return;
            }

            $remaining = $cartItem->quantity - $orderItem->quantity;
            $remaining > 0 ? $cartItem->update(['quantity' => $remaining]) : $cartItem->delete();
        });
    }

    private function appendReason(Order $order, string $reason): string
    {
        return $order->review_reason === null ? $reason : "{$order->review_reason}\n{$reason}";
    }
}
