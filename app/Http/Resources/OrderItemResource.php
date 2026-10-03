<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of an order, as it was at checkout (snapshot): later catalog changes never show here.
 *
 * @property OrderItem $resource
 */
class OrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $photoPath = $this->resource->variant_photo_path;

        return [
            /** Internal database id: shown for reference only. */
            'id' => $this->resource->id,
            'product_name' => $this->resource->product_name,
            'variant_name' => $this->resource->variant_name,
            'sku' => $this->resource->sku,
            /** The variant's cover photo at checkout; null when it had none. */
            'photo_url' => $photoPath === null ? null : app(MediaStorageService::class)->url($photoPath),
            /** Rupees as strings. */
            'original_unit_price' => $this->resource->original_unit_price,
            'selling_unit_price' => $this->resource->selling_unit_price,
            'quantity' => $this->resource->quantity,
            'subtotal' => $this->resource->subtotal,
            'discount_amount' => $this->resource->discount_amount,
            'total_amount' => $this->resource->total_amount,
        ];
    }
}
