<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\Accounts\ProfileAlreadyExists;
use App\Exceptions\Accounts\ProfileRequired;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreProfileRequest;
use App\Http\Requests\Customer\UpdateProfileRequest;
use App\Http\Resources\CustomerProfileResource;
use App\Services\Accounts\CustomerProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProfileController extends Controller
{
    /**
     * The customer's profile.
     *
     * @throws ProfileRequired
     */
    public function show(Request $request, CustomerProfileService $customerProfileService): CustomerProfileResource
    {
        return new CustomerProfileResource($customerProfileService->requireProfile($request->user()));
    }

    /**
     * Create the profile (onboarding).
     *
     * Sets the customer's name and profile details. A customer has one profile; sending this again returns 409.
     *
     * @throws ProfileAlreadyExists
     */
    public function store(StoreProfileRequest $request, CustomerProfileService $customerProfileService): JsonResponse
    {
        $profile = $customerProfileService->createProfile($request->user(), $request->toData());

        return (new CustomerProfileResource($profile))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the profile.
     *
     * @throws ProfileRequired
     */
    public function update(UpdateProfileRequest $request, CustomerProfileService $customerProfileService): CustomerProfileResource
    {
        return new CustomerProfileResource($customerProfileService->updateProfile($request->user(), $request->toData()));
    }
}
