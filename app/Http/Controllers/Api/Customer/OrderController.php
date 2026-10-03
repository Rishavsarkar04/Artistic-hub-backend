<?php

namespace App\Http\Controllers\Api\Customer;

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
     * Placed orders only (payment confirmed), newest first, paginated. Pending or failed checkouts are not listed:
     * open them by order number instead. Each row is a summary; use the order number to show the details.
     *
     * @throws ProfileRequired
     */
    public function index(ListOrdersRequest $request): AnonymousResourceCollection
    {
        $profile = $this->customerProfileService->requireProfile($request->user());

        return CustomerOrderSummaryResource::collection($this->customerOrderService->listOrders($profile, $request->perPage()));
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
