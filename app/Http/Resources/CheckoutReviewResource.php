<?php

namespace App\Http\Resources;

use App\Data\CheckoutReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The review page: the chosen address and the cart at current prices, checked and ready to pay.
 *
 * @property CheckoutReview $resource
 */
class CheckoutReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $cart = $this->resource->cart;

        return [
            'address' => new CustomerAddressResource($this->resource->address),
            /** Every item can be bought in its quantity (otherwise the review returns 422). */
            'items' => CartItemResource::collection($cart->items),
            'item_count' => $cart->itemCount(),
            /**
             * Rupees as strings. Shipping and tax are not included yet: their rules are not decided
             * (backend SRS, section 9).
             */
            'fare_breakup' => [
                'mrp_total' => $cart->mrpTotal(),
                'discount' => $cart->discount(),
                'subtotal' => $cart->subtotal(),
            ],
        ];
    }
}
