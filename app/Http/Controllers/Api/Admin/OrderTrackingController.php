<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOrderTrackingRequest;
use App\Http\Resources\AdminOrderResource;
use App\Queries\AdminOrderQuery;
use App\Services\Orders\OrderTrackingService;

class OrderTrackingController extends Controller
{
    public function __construct(
        private AdminOrderQuery $adminOrderQuery,
        private OrderTrackingService $orderTrackingService,
    ) {}

    /**
     * Save tracking details.
     *
     * Sets or corrects the tracking provider and number of a placed order: both are required, the number is kept
     * as text, and any other field in the body is rejected (422). The order becomes `completed` (fulfilled: handed
     * to the courier); `completed_at` is set the first time and kept on corrections. Pending, failed or cancelled
     * orders return 422 on `order`. Returns the order; customers see the tracking on their next fetch.
     */
    public function update(UpdateOrderTrackingRequest $request, string $order): AdminOrderResource
    {
        $updated = $this->orderTrackingService->updateTracking(
            $this->adminOrderQuery->find($order),
            trim($request->validated('tracking_provider')),
            trim($request->validated('tracking_number')),
            $request->user(),
        );

        return new AdminOrderResource($this->adminOrderQuery->find($updated->order_number));
    }
}
