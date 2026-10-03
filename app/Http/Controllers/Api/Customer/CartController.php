<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\Accounts\ProfileRequired;
use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Services\Accounts\CustomerProfileService;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private CustomerProfileService $customerProfileService,
        private CartService $cartService,
    ) {}

    /**
     * View the cart.
     *
     * Shows current prices and stock. An item that can no longer be bought stays, with an `issue`, and is left
     * out of the subtotal. Before the first add the cart is empty.
     *
     * @throws ProfileRequired
     */
    public function show(Request $request): CartResource
    {
        $profile = $this->customerProfileService->requireProfile($request->user());

        return new CartResource($this->cartService->getCart($profile));
    }
}
