<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\Accounts\ProfileRequired;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AddCartItemRequest;
use App\Http\Requests\Customer\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Services\Accounts\CustomerProfileService;
use App\Services\Cart\CartService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/** Changes to the cart's items. Each returns the whole cart, so totals and issues stay in step. */
class CartItemController extends Controller
{
    public function __construct(
        private CustomerProfileService $customerProfileService,
        private CartService $cartService,
    ) {}

    /**
     * Add a variant to the cart.
     *
     * Adding a variant already in the cart increases its quantity. Only buyable variants can be added
     * (active, of an active product, in stock), and the cart's quantity cannot be more than the stock: 422.
     *
     * @throws ProfileRequired
     */
    public function store(AddCartItemRequest $request): CartResource
    {
        $profile = $this->customerProfileService->requireProfile($request->user());

        return new CartResource($this->cartService->addItem($profile, $request->validated('product_variant_id'), $request->quantity()));
    }

    /**
     * Change an item's quantity.
     *
     * Lowering it always works. Raising it needs the variant to still be buyable, with enough stock: 422
     * otherwise. Only items in the customer's own cart can be changed; any other id returns 404.
     *
     * @throws ProfileRequired
     * @throws ModelNotFoundException
     */
    public function update(UpdateCartItemRequest $request, string $item): CartResource
    {
        $profile = $this->customerProfileService->requireProfile($request->user());

        return new CartResource($this->cartService->updateItemQuantity($profile, $item, (int) $request->validated('quantity')));
    }

    /**
     * Remove an item from the cart.
     *
     * Only items in the customer's own cart can be removed; any other id returns 404.
     *
     * @throws ProfileRequired
     * @throws ModelNotFoundException
     */
    public function destroy(Request $request, string $item): CartResource
    {
        $profile = $this->customerProfileService->requireProfile($request->user());

        return new CartResource($this->cartService->removeItem($profile, $item));
    }
}
