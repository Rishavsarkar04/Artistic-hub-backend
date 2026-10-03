<?php

namespace App\Services\Orders;

use App\Data\CustomerOrderListFilters;
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
     * stays in the list. Optionally only one status.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    public function listOrders(CustomerProfile $profile, CustomerOrderListFilters $filters): LengthAwarePaginator
    {
        return $profile->orders()
            ->whereNotNull('placed_at')
            ->when($filters->status, fn ($query, $status) => $query->where('status', $status))
            ->with(['items', 'latestPayment'])
            ->latest('placed_at')
            ->latest('id')
            ->paginate($filters->perPage);
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
