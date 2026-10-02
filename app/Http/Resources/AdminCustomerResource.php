<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer as one row of the admin customer list.
 *
 * @mixin User
 */
class AdminCustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->customerProfile;

        return [
            /** Internal database id: shown for reference only; URLs and requests take public_id. */
            'id' => $this->id,
            'public_id' => $this->public_id,
            /** Null until the customer creates their profile. */
            'name' => $this->name,
            'email' => $this->email,
            /** Shown on the row; there is no status filter. */
            'status' => $this->status,
            'profile_completed' => $profile !== null,
            'phone' => $profile?->phone,
            /** City of the default address, if any. */
            'city' => $profile?->defaultAddress?->city,
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
