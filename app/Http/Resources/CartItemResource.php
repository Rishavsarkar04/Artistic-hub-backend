<?php

namespace App\Http\Resources;

use App\Models\CartItem;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the cart, with the variant's current price and stock.
 *
 * @mixin CartItem
 */
class CartItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $variant = $this->productVariant;

        return [
            /** Internal database id: shown for reference only; URLs and requests take reference_id. */
            'id' => $this->id,
            /** Use this in PATCH / DELETE /customer/cart/items/{id}. */
            'reference_id' => $this->reference_id,
            'quantity' => $this->quantity,
            /** Current selling_price × quantity, rupees as a string. */
            'subtotal' => $this->subtotal(),
            /** Current original_price × quantity: the struck-through line price when discounted. */
            'original_subtotal' => $this->originalSubtotal(),
            /**
             * Null when the item can be bought as it is. Otherwise unavailable (turned off), out_of_stock, or
             * not_enough_stock (fewer left than the quantity). Such items are left out of the subtotal.
             */
            'issue' => $this->issue()?->value,
            'product_variant' => [
                'id' => $variant->id,
                'reference_id' => $variant->reference_id,
                'name' => $variant->name,
                'slug' => $variant->slug,
                'product' => [
                    'id' => $variant->product->id,
                    'reference_id' => $variant->product->reference_id,
                    'name' => $variant->product->name,
                    'slug' => $variant->product->slug,
                ],
                /** Rupees as a string, e.g. "1199.00". */
                'original_price' => $variant->original_price,
                /** What the customer pays per unit, now. */
                'selling_price' => $variant->selling_price,
                ...Currency::fields(),
                /** Units left: the most this item's quantity can be. */
                'stock' => $variant->stock,
                /** The first photo, or null. */
                'cover_url' => $variant->photos->first()?->url(),
            ],
        ];
    }
}
