<?php

namespace App\Queries;

use App\Data\OrderListFilters;
use App\Enums\OrderSort;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Read-only query behind the admin order list: placed orders only (placed_at set, i.e. payment confirmed).
 * Pending and failed checkouts are not orders yet. Customer fields come from the order's snapshot; the
 * profile and user are loaded with soft-deleted rows so an old order still links to its customer.
 */
final class AdminOrderQuery
{
    /** @return LengthAwarePaginator<int, Order> */
    public function paginate(OrderListFilters $filters): LengthAwarePaginator
    {
        $query = Order::query()
            ->whereNotNull('placed_at')
            ->withSum('items as item_count', 'quantity')
            ->with([
                'latestPayment',
                'customerProfile' => fn ($profile) => $profile->withTrashed()->with(['user' => fn ($user) => $user->withTrashed()]),
            ]);

        if ($filters->search !== null) {
            $this->applySearch($query, $filters->search);
        }

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        if ($filters->customerId !== null) {
            $query->whereHas('customerProfile', fn (Builder $profile) => $profile->withTrashed()
                ->whereHas('user', fn (Builder $user) => $user->withTrashed()->where('reference_id', $filters->customerId)));
        }

        match ($filters->sort) {
            OrderSort::Newest => $query->latest('placed_at')->latest('id'),
            OrderSort::Oldest => $query->oldest('placed_at')->oldest('id'),
            OrderSort::TotalHigh => $query->orderByDesc('total_amount')->latest('id'),
            OrderSort::TotalLow => $query->orderBy('total_amount')->latest('id'),
        };

        return $query->paginate($filters->perPage);
    }

    /**
     * One order by its number, in any status (support may look up a pending or failed checkout), with its
     * items, payments and customer.
     *
     * @throws ModelNotFoundException
     */
    public function find(string $orderNumber): Order
    {
        return Order::where('order_number', $orderNumber)
            ->with([
                'items',
                'payments',
                'latestPayment',
                'customerProfile' => fn ($profile) => $profile->withTrashed()->with(['user' => fn ($user) => $user->withTrashed()]),
            ])
            ->firstOrFail();
    }

    /** Matches the order number, the customer snapshot or the delivery city; LIKE wildcards are literal. */
    private function applySearch(Builder $query, string $search): void
    {
        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function (Builder $where) use ($term) {
            $where->where('order_number', 'like', $term)
                ->orWhere('customer_name', 'like', $term)
                ->orWhere('customer_email', 'like', $term)
                ->orWhere('customer_phone', 'like', $term)
                ->orWhere('city', 'like', $term);
        });
    }
}
