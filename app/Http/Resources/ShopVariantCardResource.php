<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card in the shop listing: a buyable product variant.
 *
 * @mixin ProductVariant
 */
class ShopVariantCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        [$cover, $hover] = [$this->photos->get(0), $this->photos->get(1)];

        return [
            'id' => $this->id,
            'reference_id' => $this->reference_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'product' => [
                'id' => $this->product->id,
                'reference_id' => $this->product->reference_id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
            ],
            /** Rupees as a string, e.g. "1199.00". */
            'original_price' => $this->original_price,
            /** What the customer pays; lower than original_price when discounted. */
            'selling_price' => $this->selling_price,
            /** The prices' currency (the shop currency) and its display symbol. */
            ...Currency::fields(),
            /** The first photo, or null. */
            'cover_url' => $cover?->url(),
            /** The second photo, shown on hover; null when there is only one photo (keep showing the cover). */
            'hover_url' => $hover?->url(),
            'tags' => TagResource::collection($this->tags),
        ];
    }
}
