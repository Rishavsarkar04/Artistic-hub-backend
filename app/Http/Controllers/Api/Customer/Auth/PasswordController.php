<?php

namespace App\Http\Controllers\Api\Customer\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Services\Accounts\AuthTokenService;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    public function __construct(private AuthTokenService $authTokenService) {}

    /**
     * Change your password.
     *
     * Needs the current password (422 on `current_password` when it is wrong) and a new one that differs from it.
     * Every other session is signed out; this one stays signed in. Limited to 6 attempts a minute.
     */
    public function update(ChangePasswordRequest $request): JsonResponse
    {
        $this->authTokenService->changePassword($request->user(), $request->validated('password'));

        return response()->json(['message' => 'Your password has been changed. Other devices have been signed out.']);
    }
}
