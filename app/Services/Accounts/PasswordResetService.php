<?php

namespace App\Services\Accounts;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Forgot / reset password for one role, on Laravel's password broker (tokens in password_reset_tokens,
 * hashed, single use, expiring after auth.passwords.users.expire minutes). Each area only acts on its own
 * role: the customer endpoints never touch an admin account, and the other way round.
 */
final class PasswordResetService
{
    /**
     * Emails a reset link when the email belongs to an account of this role. Does nothing otherwise, and also
     * when a link was sent less than auth.passwords.users.throttle seconds ago: the caller always answers the
     * same, so the response never reveals whether an email exists or which role it has.
     */
    public function sendResetLink(string $email, Role $role): void
    {
        $user = $this->findUser($email, $role);
        if ($user === null) {
            return;
        }

        Password::broker()->sendResetLink(
            ['email' => $user->email],
            fn (User $user, string $token) => $user->notify(new ResetPasswordNotification($token, $role)),
        );
    }

    /**
     * Sets the new password when the token is valid for this email and role, then signs the user out
     * everywhere (every token revoked) and deletes the token, so the link works once.
     *
     * @throws ValidationException `token`: invalid, used, expired, or for an account of another role
     */
    public function resetPassword(string $email, string $token, string $password, Role $role): void
    {
        $user = $this->findUser($email, $role);

        $status = $user === null ? Password::INVALID_USER : Password::broker()->reset(
            ['email' => $user->email, 'token' => $token, 'password' => $password],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->update(['revoked' => true]);

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => 'This password reset link is invalid or has expired. Request a new one.',
            ]);
        }
    }

    private function findUser(string $email, Role $role): ?User
    {
        $user = User::where('email', $email)->first();

        return $user !== null && $user->hasRole($role) ? $user : null;
    }
}
