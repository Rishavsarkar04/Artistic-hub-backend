<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\Accounts\ProfileRequired;
use App\Exceptions\Checkout\CheckoutInProgress;
use App\Exceptions\Checkout\PaymentGatewayUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CheckoutRequest;
use App\Http\Requests\Customer\ReviewCheckoutRequest;
use App\Http\Resources\CheckoutPaymentResource;
use App\Http\Resources\CheckoutReviewResource;
use App\Services\Accounts\CustomerProfileService;
use App\Services\Checkout\CheckoutService;

class CheckoutController extends Controller
{
    public function __construct(
        private CustomerProfileService $customerProfileService,
        private CheckoutService $checkoutService,
    ) {}

    /**
     * Review the order.
     *
     * Checks everything the payment will check (the address is yours, the cart is not empty, every item can be
     * bought in its quantity) and returns the address, items and fare_breakup at current prices. Changes nothing.
     * 422 names what to fix: `address_id`, `cart`, or each item as `items.N`.
     *
     * @throws ProfileRequired
     */
    public function review(ReviewCheckoutRequest $request): CheckoutReviewResource
    {
        $profile = $this->customerProfileService->requireProfile($request->user());

        return new CheckoutReviewResource($this->checkoutService->review($profile, $request->validated('address_id')));
    }

    /**
     * Pay for the cart.
     *
     * Runs the review checks again (same 422s), creates a pending order from the cart at current prices and
     * returns a Razorpay `payment_url` to redirect the browser to. The total is worked out here, never sent by
     * the client. Pressing Pay again for the same cart and address returns the same link while it is valid.
     * The cart and stock are unchanged until the payment is confirmed. 409 while the same checkout is still
     * being prepared; 503 when Razorpay cannot start the payment (try again).
     *
     * @throws ProfileRequired
     * @throws CheckoutInProgress
     * @throws PaymentGatewayUnavailable
     */
    public function store(CheckoutRequest $request): CheckoutPaymentResource
    {
        $profile = $this->customerProfileService->requireProfile($request->user());

        return new CheckoutPaymentResource($this->checkoutService->checkout($profile, $request->validated('address_id')));
    }
}
