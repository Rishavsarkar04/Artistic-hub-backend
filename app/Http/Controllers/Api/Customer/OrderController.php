<?php

namespace App\Http\Controllers\Api\Customer;

use App\Data\CustomerOrderListFilters;
use App\Exceptions\Accounts\ProfileRequired;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ListOrdersRequest;
use App\Http\Resources\CustomerOrderResource;
use App\Http\Resources\CustomerOrderSummaryResource;
use App\Services\Accounts\CustomerProfileService;
use App\Services\Orders\CustomerOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(
        private CustomerProfileService $customerProfileService,
        private CustomerOrderService $customerOrderService,
    ) {}

    /**
     * List your orders.
     *
     * Placed orders only (payment confirmed), newest first, paginated; `status` narrows to one status. Pending or
     * failed checkouts are not listed: open them by order number instead. Each row is a summary; use the order
     * number to show the details. The response also has `filters` (as applied) and `filter_options` (the values
     * `status` accepts).
     *
     * @throws ProfileRequired
     */
    public function index(ListOrdersRequest $request): AnonymousResourceCollection
    {
        $profile = $this->customerProfileService->requireProfile($request->user());
        $filters = $request->toFilters();

        return CustomerOrderSummaryResource::collection($this->customerOrderService->listOrders($profile, $filters))
            ->additional([
                'filters' => $filters->toArray(),
                'filter_options' => CustomerOrderListFilters::options(),
            ]);
    }

    /**
     * Show an order.
     *
     * Any of your orders by its order number, including a pending or cancelled checkout: the payment result page
     * (where Razorpay returns with `?order=`) calls this. `status` is the order status and `payment.status` the
     * newest payment's status; while `payment.can_pay` is true, `payment.payment_url` can be opened again.
     * Another customer's order number returns 404.
     *
     * @throws ProfileRequired
     */
    public function show(Request $request, string $order): CustomerOrderResource
    {
        $profile = $this->customerProfileService->requireProfile($request->user());

        return new CustomerOrderResource($this->customerOrderService->getOrder($profile, $order));
    }
}
