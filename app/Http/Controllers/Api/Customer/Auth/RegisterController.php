<?php

namespace App\Http\Controllers\Api\Customer\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Auth\RegisterRequest;
use App\Http\Resources\CustomerResource;
use App\Services\Accounts\CustomerRegistrationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RegisterController extends Controller
{
    /**
     * Register a customer.
     *
     * Creates an active customer account from email and password only. No token is returned: the
     * customer signs in next, then creates their profile.
     *
     * @unauthenticated
     */
    public function store(RegisterRequest $request, CustomerRegistrationService $customerRegistrationService): JsonResponse
    {
        $customer = $customerRegistrationService->register($request->validated('email'), $request->validated('password'));

        return (new CustomerResource($customer))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
