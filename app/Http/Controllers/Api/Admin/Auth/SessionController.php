<?php

namespace App\Http\Controllers\Api\Admin\Auth;

use App\Enums\Role;
use App\Exceptions\Auth\AccountNotActive;
use App\Exceptions\Auth\InvalidCredentials;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\LoginRequest;
use App\Http\Resources\AdminResource;
use App\Http\Resources\AdminTokenResource;
use App\Services\Accounts\AuthTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SessionController extends Controller
{
    /**
     * Sign in as an admin.
     *
     * Only admin accounts can sign in here; customer accounts get the same error as a wrong password.
     *
     * @unauthenticated
     *
     * @throws InvalidCredentials
     * @throws AccountNotActive
     */
    public function store(LoginRequest $request, AuthTokenService $authTokenService): AdminTokenResource
    {
        return new AdminTokenResource(
            $authTokenService->signIn($request->validated('email'), $request->validated('password'), Role::Admin),
        );
    }

    /** The signed-in admin. */
    public function show(Request $request): AdminResource
    {
        return new AdminResource($request->user());
    }

    /** Sign out (revokes the current token). */
    public function destroy(Request $request, AuthTokenService $authTokenService): Response
    {
        $authTokenService->signOut($request->user());

        return response()->noContent();
    }
}
