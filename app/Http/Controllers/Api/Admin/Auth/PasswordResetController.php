<?php

namespace App\Http\Controllers\Api\Admin\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Accounts\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetService $passwordResetService) {}

    /**
     * Request a password reset email (admin).
     *
     * Always answers the same, whether or not the email belongs to a admin account, so it never reveals which
     * emails exist. When it does, a link to the admin reset page is emailed; it expires in 60 minutes.
     *
     * @unauthenticated
     */
    public function sendLink(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->sendResetLink($request->validated('email'), Role::Admin);

        return response()->json(['message' => "If an account exists for that email, we've sent a link to reset the password."]);
    }

    /**
     * Reset the password (admin).
     *
     * Takes the `email` and `token` from the reset link and the new password. On success every session of the
     * account is signed out; sign in again with the new password. 422 on `token` when the link is invalid, used
     * or expired.
     *
     * @unauthenticated
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->resetPassword(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
            Role::Admin,
        );

        return response()->json(['message' => 'Your password has been reset. Sign in with your new password.']);
    }
}
