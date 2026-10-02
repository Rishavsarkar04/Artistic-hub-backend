<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\Accounts\ProfileRequired;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreAddressRequest;
use App\Http\Requests\Customer\UpdateAddressRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Services\Accounts\CustomerAddressService;
use App\Services\Accounts\CustomerProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AddressController extends Controller
{
    public function __construct(
        private CustomerProfileService $customerProfileService,
        private CustomerAddressService $customerAddressService,
    ) {}

    /**
     * List saved addresses.
     *
     * The default address comes first, then the newest.
     *
     * @throws ProfileRequired
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $profile = $this->customerProfileService->requireProfile($request->user());

        return CustomerAddressResource::collection($this->customerAddressService->listAddresses($profile));
    }

    /**
     * Add an address.
     *
     * The customer's first address becomes the default automatically.
     *
     * @throws ProfileRequired
     */
    public function store(StoreAddressRequest $request): JsonResponse
    {
        $profile = $this->customerProfileService->requireProfile($request->user());
        $address = $this->customerAddressService->createAddress($profile, $request->toData());

        return (new CustomerAddressResource($address))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update an address.
     *
     * Only the customer's own addresses can be changed; any other id returns 404. Past orders keep their own copy.
     *
     * @throws ProfileRequired
     */
    public function update(UpdateAddressRequest $request, int $address): CustomerAddressResource
    {
        $profile = $this->customerProfileService->requireProfile($request->user());

        return new CustomerAddressResource($this->customerAddressService->updateAddress($profile, $address, $request->toData()));
    }
}
