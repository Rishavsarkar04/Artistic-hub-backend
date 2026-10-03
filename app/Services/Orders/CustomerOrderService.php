<?php

namespace App\Services\Orders;

use App\Models\CustomerProfile;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** The customer's own orders. Another customer's order number is simply "not found". */
final class CustomerOrderService
{
    /**
     * The customer's placed orders (placed_at set, i.e. payment confirmed), newest first. Pending and
     * failed checkouts are not orders yet, so they are left out; an order cancelled after it was placed
     * stays in the list.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    public function listOrders(CustomerProfile $profile, int $perPage): LengthAwarePaginator
    {
        return $profile->orders()
            ->whereNotNull('placed_at')
            ->with(['items', 'latestPayment'])
            ->latest('placed_at')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * One order with its items and newest payment, in any status (the payment result page also shows
     * pending and cancelled checkouts).
     *
     * @throws ModelNotFoundException when the order is not this customer's
     */
    public function getOrder(CustomerProfile $profile, string $orderNumber): Order
    {
        return $profile->orders()
            ->where('order_number', $orderNumber)
            ->with(['items', 'latestPayment'])
            ->firstOrFail();
    }
}
