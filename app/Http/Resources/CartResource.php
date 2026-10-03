<?php

namespace App\Http\Resources;

use App\Models\Cart;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer's cart with current prices. Items that can no longer be bought stay in the cart with an
 * `issue`, and are left out of the subtotal.
 *
 * @mixin Cart
 */
class CartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** In the order they were added. */
            'items' => CartItemResource::collection($this->items),
            /** Total units across all items (for the cart badge). */
            'item_count' => $this->itemCount(),
            /** Sum of the subtotals of items with no issue, rupees as a string, e.g. "1798.00". */
            'subtotal' => $this->subtotal(),
            /** True when any item has an issue: ask the customer to fix the cart before checkout. */
            'has_issues' => $this->hasIssues(),
            /**
             * Price breakup of the items with no issue, rupees as strings. Shipping and tax are not included:
             * their rules are not decided yet (backend SRS, BE-CHECKOUT-02).
             */
            'fare_breakup' => [
                /** The amounts' currency (the shop currency) and its display symbol. */
                ...Currency::fields(),
                /** Original price (MRP) × quantity, summed. */
                'mrp_total' => $this->mrpTotal(),
                /** mrp_total − subtotal: what the customer saves. */
                'discount' => $this->discount(),
                /** Selling price × quantity, summed (same as `subtotal`). */
                'subtotal' => $this->subtotal(),
            ],
        ];
    }
}
