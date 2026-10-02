<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\Accounts\ProfileRequired;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerAddressResource;
use App\Services\Accounts\CustomerAddressService;
use App\Services\Accounts\CustomerProfileService;
use Illuminate\Http\Request;

class AddressDefaultController extends Controller
{
    /**
     * Make an address the default.
     *
     * The previous default stops being the default. Only the customer's own addresses; any other id returns 404.
     *
     * @throws ProfileRequired
     */
    public function update(
        Request $request,
        int $address,
        CustomerProfileService $customerProfileService,
        CustomerAddressService $customerAddressService,
    ): CustomerAddressResource {
        $profile = $customerProfileService->requireProfile($request->user());

        return new CustomerAddressResource($customerAddressService->setDefaultAddress($profile, $address));
    }
}
