<?php

namespace App\Http\Controllers\Api\Customer\Auth;

use App\Enums\Role;
use App\Exceptions\Auth\AccountNotActive;
use App\Exceptions\Auth\InvalidCredentials;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Auth\LoginRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\CustomerTokenResource;
use App\Services\Accounts\AuthTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SessionController extends Controller
{
    /**
     * Sign in as a customer.
     *
     * Only customer accounts can sign in here; admin accounts get the same error as a wrong password.
     *
     * @unauthenticated
     *
     * @throws InvalidCredentials
     * @throws AccountNotActive
     */
    public function store(LoginRequest $request, AuthTokenService $authTokenService): CustomerTokenResource
    {
        return new CustomerTokenResource(
            $authTokenService->signIn($request->validated('email'), $request->validated('password'), Role::Customer),
        );
    }

    /** The signed-in customer. */
    public function show(Request $request): CustomerResource
    {
        return new CustomerResource($request->user());
    }

    /** Sign out (revokes the current token). */
    public function destroy(Request $request, AuthTokenService $authTokenService): Response
    {
        $authTokenService->signOut($request->user());

        return response()->noContent();
    }
}
