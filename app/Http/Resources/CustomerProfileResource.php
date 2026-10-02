<?php

namespace App\Http\Resources;

use App\Models\CustomerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A customer's own profile. The internal `notes` are never included.
 *
 * @mixin CustomerProfile
 */
class CustomerProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** Stored on the user account; set and changed with the profile. */
            'name' => $this->user->name,
            'phone' => $this->phone,
            /** Y-m-d, or null. */
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            /** Null until avatar upload exists. */
            'avatar_url' => $this->avatar_path ? Storage::url($this->avatar_path) : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
