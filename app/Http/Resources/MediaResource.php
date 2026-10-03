<?php

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** An uploaded file. @mixin Media */
class MediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** Send this to attach the file (e.g. in variants.*.photo_ids). */
            /** Internal database id: shown for reference only; URLs and requests take reference_id. */
            'id' => $this->id,
            'reference_id' => $this->reference_id,
            'url' => $this->url(),
            'mime_type' => $this->mime_type,
            /** Bytes. */
            'size' => $this->size,
            /** Position among its owner's media; 0 is the cover. */
            'sort_order' => $this->sort_order,
        ];
    }
}
