<?php

namespace App\Http\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tag */
class TagResource extends JsonResource
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
        ];
    }
}
