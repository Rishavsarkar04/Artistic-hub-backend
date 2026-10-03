<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in customer.
 *
 * @mixin User
 */
class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** Internal database id: shown for reference only; URLs and requests take reference_id. */
            'id' => $this->id,
            'reference_id' => $this->reference_id,
            /** Null until the customer creates their profile. */
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'role' => 'customer',
            /** False until the profile exists; the frontend sends the customer to onboarding. */
            'profile_completed' => $this->customerProfile !== null,
            /** The profile details, or null until the customer creates their profile. */
            'profile' => $this->customerProfile ? new CustomerProfileResource($this->customerProfile) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
