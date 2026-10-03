<?php

namespace App\Services\Checkout;

use App\Contracts\PaymentGateway;
use App\Data\CheckoutReview;
use App\Data\PaymentLinkRequest;
use App\Enums\CartItemIssue;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\Checkout\CheckoutInProgress;
use App\Exceptions\Checkout\PaymentGatewayUnavailable;
use App\Models\CartItem;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Cart\CartService;
use App\Services\Payments\PaymentCancellationService;
use App\Support\Currency;
use App\Support\Money;
use App\Support\OrderNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checkout from the customer's server cart. review() runs every check the payment step will run and
 * changes nothing; checkout() runs the same checks, creates a pending order with snapshots and asks
 * Razorpay for a payment link. Prices and totals always come from current variant data, never from
 * the client. Neither the cart nor the stock changes here: both change only when the payment is
 * confirmed (no stock reservation for now).
 */
final class CheckoutService
{
    /** How long a checkout may wait for Razorpay before a repeat is no longer "in progress". */
    private const IN_PROGRESS_SECONDS = 60;

    public function __construct(
        private CartService $cartService,
        private PaymentGateway $paymentGateway,
        private PaymentCancellationService $paymentCancellationService,
    ) {}

    /**
     * Checks that the order can be placed: the address is the customer's, the cart is not empty, and
     * every item can be bought in its quantity.
     *
     * @param  string  $addressId  the address's reference_id
     *
     * @throws ValidationException naming the address, the empty cart, or each item to fix (items.N)
     */
    public function review(CustomerProfile $profile, string $addressId): CheckoutReview
    {
        $address = $this->findAddress($profile, $addressId);
        $cart = $this->cartService->getCart($profile);

        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
        }

        $problems = [];
        foreach ($cart->items->values() as $index => $item) {
            if ($issue = $item->issue()) {
                $problems["items.{$index}"] = $this->describe($item, $issue);
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return new CheckoutReview($address, $cart);
    }

    /**
     * Starts the payment: checks like review(), creates a pending order (customer, address and item
     * snapshots) and its payment, then creates a Razorpay payment link for the total. Pressing Pay again
     * for the same cart and address returns the same link while it is still payable.
     *
     * @param  string  $addressId  the address's reference_id
     *
     * @throws ValidationException as review()
     * @throws CheckoutInProgress when the same checkout is still waiting for Razorpay
     * @throws PaymentGatewayUnavailable when Razorpay cannot create the link (the order is cancelled)
     */
    public function checkout(CustomerProfile $profile, string $addressId): Payment
    {
        [$payment, $isNew] = DB::transaction(function () use ($profile, $addressId) {
            // One checkout at a time per customer (the cart changes under the same lock).
            CustomerProfile::whereKey($profile->getKey())->lockForUpdate()->first();

            $review = $this->review($profile, $addressId);

            if ($existing = $this->findSamePendingPayment($profile, $review)) {
                return [$existing, false];
            }

            return [$this->createPendingOrder($profile, $review), true];
        });

        if (! $isNew) {
            return $payment;
        }

        $payment = $this->createPaymentLink($payment);
        $this->cancelOlderLinks($profile, $payment);

        return $payment;
    }

    /**
     * The latest pending checkout when it is for the same address and the same items, quantities and
     * prices: its payment when still payable; CheckoutInProgress when its link is still being created.
     */
    private function findSamePendingPayment(CustomerProfile $profile, CheckoutReview $review): ?Payment
    {
        $order = $profile->orders()
            ->where('status', OrderStatus::Pending)
            ->with(['items', 'latestPayment'])
            ->latest('id')
            ->first();

        $payment = $order?->latestPayment;

        if ($payment === null || ! $this->isSameCheckout($order, $review)) {
            return null;
        }

        if ($payment->isPayable()) {
            return $payment;
        }

        $stillCreating = $payment->status === PaymentStatus::Pending
            && $payment->payment_url === null
            && $payment->created_at->diffInSeconds(now()) < self::IN_PROGRESS_SECONDS;

        if ($stillCreating) {
            throw new CheckoutInProgress;
        }

        return null;
    }

    private function isSameCheckout(Order $order, CheckoutReview $review): bool
    {
        $address = $review->address;
        $addressSnapshot = [
            $address->recipient_name,
            $address->phone,
            $address->address_line_1,
            $address->address_line_2,
            $address->city,
            $address->state,
            $address->postal_code,
            $address->country,
        ];

        $orderAddress = [
            $order->recipient_name,
            $order->recipient_phone,
            $order->address_line_1,
            $order->address_line_2,
            $order->city,
            $order->state,
            $order->postal_code,
            $order->country,
        ];

        $cartLines = $review->cart->items
            ->map(fn (CartItem $item) => "{$item->product_variant_id}:{$item->quantity}:{$item->productVariant->selling_price}")
            ->sort()->values()->all();

        $orderLines = $order->items
            ->map(fn (OrderItem $item) => "{$item->product_variant_id}:{$item->quantity}:{$item->selling_unit_price}")
            ->sort()->values()->all();

        return $addressSnapshot === $orderAddress && $cartLines === $orderLines;
    }

    /** The pending order with its snapshots and a pending payment for the total (no link yet). */
    private function createPendingOrder(CustomerProfile $profile, CheckoutReview $review): Payment
    {
        $address = $review->address;
        $cart = $review->cart;
        $subtotal = $cart->subtotal();
        // Prices include GST and shipping is not charged for now (backend SRS, section 9).
        $discountAmount = Money::normalize('0');
        $shippingAmount = Money::normalize('0');

        $order = new Order;
        $order->forceFill([
            'customer_profile_id' => $profile->id,
            'order_number' => OrderNumber::unique(now(), fn (string $orderNumber) => Order::where('order_number', $orderNumber)->exists()),
            'status' => OrderStatus::Pending,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'shipping_amount' => $shippingAmount,
            'total_amount' => Money::add(Money::subtract($subtotal, $discountAmount), $shippingAmount),
            'currency' => Currency::shop(),
            'customer_name' => $profile->user->name,
            'customer_email' => $profile->user->email,
            'customer_phone' => $profile->phone,
            'recipient_name' => $address->recipient_name,
            'recipient_phone' => $address->phone,
            'address_line_1' => $address->address_line_1,
            'address_line_2' => $address->address_line_2,
            'city' => $address->city,
            'state' => $address->state,
            'postal_code' => $address->postal_code,
            'country' => $address->country,
        ])->save();

        foreach ($cart->items as $cartItem) {
            $variant = $cartItem->productVariant;
            $orderItem = new OrderItem;
            $orderItem->forceFill([
                'product_variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'sku' => $variant->sku,
                'variant_photo_path' => $variant->photos->first()?->path,
                'original_unit_price' => $variant->original_price,
                'selling_unit_price' => $variant->selling_price,
                'quantity' => $cartItem->quantity,
                'subtotal' => $cartItem->subtotal(),
                'discount_amount' => Money::normalize('0'),
                'total_amount' => $cartItem->subtotal(),
            ]);
            $order->items()->save($orderItem);
        }

        $payment = new Payment;
        $payment->forceFill([
            'payment_number' => 'PAY'.strtoupper((string) Str::ulid()),
            'provider' => 'razorpay',
            'status' => PaymentStatus::Pending,
            'amount' => $order->total_amount,
            'currency' => $order->currency,
        ]);
        $order->payments()->save($payment);

        return $payment->setRelation('order', $order);
    }

    /**
     * Called after the order is committed, so a slow Razorpay call holds no lock. On failure the payment
     * is marked failed and the order cancelled; the customer can simply press Pay again.
     *
     * @throws PaymentGatewayUnavailable
     */
    private function createPaymentLink(Payment $payment): Payment
    {
        $order = $payment->order;
        $expiresAt = CarbonImmutable::now()->addMinutes(config('services.razorpay.link_expiry_minutes'));

        try {
            $link = $this->paymentGateway->createPaymentLink(new PaymentLinkRequest(
                referenceId: $payment->payment_number,
                amount: $payment->amount,
                currency: $payment->currency,
                description: "Artistic Hub order {$order->order_number}",
                customerName: $order->customer_name,
                customerEmail: $order->customer_email,
                customerPhone: $order->customer_phone,
                callbackUrl: config('services.razorpay.callback_url').'?'.http_build_query(['order' => $order->order_number]),
                expiresAt: $expiresAt,
                notes: ['order_number' => $order->order_number, 'payment_number' => $payment->payment_number],
            ));
        } catch (PaymentGatewayUnavailable $exception) {
            DB::transaction(function () use ($payment, $order, $exception) {
                $payment->forceFill([
                    'status' => PaymentStatus::Failed,
                    'failed_at' => now(),
                    'failure_reason' => $exception->reason,
                ])->save();
                $order->forceFill([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'The payment could not be started.',
                ])->save();
            });

            report($exception);

            throw $exception;
        }

        $payment->forceFill([
            'payment_session_id' => $link->id,
            'payment_url' => $link->url,
            'expires_at' => $expiresAt,
            'gateway_response' => $link->response,
        ])->save();

        return $payment;
    }

    /**
     * Cancels the customer's other links that can still be paid, so an old tab cannot charge them twice.
     * Best effort: when Razorpay refuses (typically because that link was just paid), it is left alone and
     * the webhook confirms that order. Runs after the new link exists, outside any transaction.
     */
    private function cancelOlderLinks(CustomerProfile $profile, Payment $newPayment): void
    {
        $olderPayments = Payment::whereKeyNot($newPayment->id)
            ->whereHas('order', fn ($query) => $query->where('customer_profile_id', $profile->id))
            ->where('status', PaymentStatus::Pending)
            ->whereNotNull('payment_session_id')
            ->where('expires_at', '>', now())
            ->get();

        foreach ($olderPayments as $olderPayment) {
            try {
                $this->paymentGateway->cancelPaymentLink($olderPayment->payment_session_id);
            } catch (PaymentGatewayUnavailable $exception) {
                report($exception);

                continue;
            }

            $this->paymentCancellationService->cancelPending($olderPayment, 'Replaced by a newer checkout.');
        }
    }

    /** Another customer's address gets the same message as a missing one. */
    private function findAddress(CustomerProfile $profile, string $addressId): CustomerAddress
    {
        return $profile->addresses()->where('reference_id', $addressId)->first()
            ?? throw ValidationException::withMessages(['address_id' => 'This address was not found. Choose another one.']);
    }

    private function describe(CartItem $item, CartItemIssue $issue): string
    {
        $name = "{$item->productVariant->product->name} ({$item->productVariant->name})";

        return match ($issue) {
            CartItemIssue::Unavailable => "{$name} is no longer available. Remove it to continue.",
            CartItemIssue::OutOfStock => "{$name} is out of stock. Remove it to continue.",
            CartItemIssue::NotEnoughStock => "Only {$item->productVariant->stock} left of {$name}. Lower the quantity to continue.",
        };
    }
}
