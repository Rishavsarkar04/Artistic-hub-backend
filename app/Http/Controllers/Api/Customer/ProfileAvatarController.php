<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\Accounts\ProfileRequired;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\UpdateAvatarRequest;
use App\Http\Resources\CustomerProfileResource;
use App\Services\Accounts\CustomerProfileService;
use Illuminate\Http\Request;

class ProfileAvatarController extends Controller
{
    public function __construct(private CustomerProfileService $customerProfileService) {}

    /**
     * Upload or replace the avatar.
     *
     * multipart/form-data with an `avatar` file. Replaces any previous avatar. Returns the profile with the new `avatar_url`.
     *
     * @throws ProfileRequired
     */
    public function update(UpdateAvatarRequest $request): CustomerProfileResource
    {
        return new CustomerProfileResource(
            $this->customerProfileService->updateAvatar($request->user(), $request->file('avatar')),
        );
    }

    /**
     * Remove the avatar.
     *
     * @throws ProfileRequired
     */
    public function destroy(Request $request): CustomerProfileResource
    {
        return new CustomerProfileResource($this->customerProfileService->removeAvatar($request->user()));
    }
}
