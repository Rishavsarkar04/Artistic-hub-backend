<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class AdminProductVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** Internal database id: shown for reference only; URLs and requests take reference_id. */
            'id' => $this->id,
            'reference_id' => $this->reference_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'slug' => $this->slug,
            'description' => $this->description,
            /** Rupees as a string, e.g. "999.00". */
            'original_price' => $this->original_price,
            /** Rupees as a string; never more than original_price. */
            'selling_price' => $this->selling_price,
            /** The prices' currency (the shop currency) and its display symbol. */
            ...Currency::fields(),
            'stock' => $this->stock,
            'is_active' => $this->is_active,
            /** In display order; the first is the cover. Send their reference_ids back as photo_ids to keep them. */
            'photos' => MediaResource::collection($this->whenLoaded('photos')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
