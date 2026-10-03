<?php

namespace App\Http\Resources;

use App\Models\Media;
use App\Models\ProductVariant;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One variant on the shop's details page: the exact variant picked, with its product, photos and tags.
 *
 * @mixin ProductVariant
 */
class ShopVariantDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** Internal database id: shown for reference only; URLs and requests take reference_id. */
            'id' => $this->id,
            'reference_id' => $this->reference_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            /** The variant's own description, or the product's when it has none. */
            'description' => $this->description ?? $this->product->description,
            /** Rupees as a string, e.g. "1199.00". */
            'original_price' => $this->original_price,
            /** What the customer pays; lower than original_price when discounted. */
            'selling_price' => $this->selling_price,
            /** The prices' currency (the shop currency) and its display symbol. */
            ...Currency::fields(),
            /** Units left; the most that can be added to the cart. */
            'stock' => $this->stock,
            /** False when stock is 0: show "Out of stock" and disable Add to cart. */
            'in_stock' => $this->stock > 0,
            'product' => [
                'id' => $this->product->id,
                'reference_id' => $this->product->reference_id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'description' => $this->product->description,
            ],
            /** The gallery, in display order; the first is the cover. */
            'photos' => $this->photos->map(fn (Media $photo) => [
                'id' => $photo->id,
                'reference_id' => $photo->reference_id,
                'url' => $photo->url(),
            ])->values()->all(),
            'tags' => TagResource::collection($this->tags),
        ];
    }
}
