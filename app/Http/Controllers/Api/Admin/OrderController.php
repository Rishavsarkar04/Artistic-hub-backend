<?php

namespace App\Http\Controllers\Api\Admin;

use App\Data\OrderListFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListOrdersRequest;
use App\Http\Resources\AdminOrderResource;
use App\Http\Resources\AdminOrderSummaryResource;
use App\Queries\AdminOrderQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(private AdminOrderQuery $adminOrderQuery) {}

    /**
     * List orders.
     *
     * Placed orders only (payment confirmed), paginated, newest first by default. Pending and failed checkouts
     * are not listed. Search matches order number, customer name, email and phone, and the delivery city;
     * `customer_id` (a customer's reference_id) shows only that customer's orders. The response also has
     * `filters` (as applied, with defaults filled in) and `filter_options` (the values `status` and `sort` accept).
     */
    public function index(ListOrdersRequest $request): AnonymousResourceCollection
    {
        $filters = $request->toFilters();

        return AdminOrderSummaryResource::collection($this->adminOrderQuery->paginate($filters))
            ->additional([
                'filters' => $filters->toArray(),
                'filter_options' => OrderListFilters::options(),
            ]);
    }

    /**
     * Show an order.
     *
     * Any order by its order number, in any status (also a pending or failed checkout a customer asks about).
     * Everything the customer sees, plus `review_reason` (why it needs attention, e.g. paid when stock was short),
     * the customer's `reference_id`, and every payment attempt with its Razorpay ids. Unknown numbers return 404.
     */
    public function show(string $order): AdminOrderResource
    {
        return new AdminOrderResource($this->adminOrderQuery->find($order));
    }
}
