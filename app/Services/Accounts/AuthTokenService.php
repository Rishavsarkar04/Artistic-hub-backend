<?php

namespace App\Services\Accounts;

use App\Data\IssuedToken;
use App\Enums\Role;
use App\Exceptions\Auth\AccountNotActive;
use App\Exceptions\Auth\InvalidCredentials;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\AccessToken;

final class AuthTokenService
{
    /**
     * Checks the credentials for one role and issues a token scoped to that role. Admins and
     * customers sign in through separate endpoints; the other role gets the same error as a
     * wrong password. Soft-deleted users are never found.
     *
     * @throws InvalidCredentials
     * @throws AccountNotActive
     */
    public function signIn(string $email, string $password, Role $role): IssuedToken
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password) || ! $user->hasRole($role)) {
            throw new InvalidCredentials;
        }

        if (! $user->isActive()) {
            throw new AccountNotActive;
        }

        $result = $user->createToken("{$role->value}-session", [$role->scope()]);

        // Passport gives every personal token the same expiry; store this role's real one on the token row.
        // EnsureTokenIsFresh enforces it, so the database, the response and the check always agree.
        $expiresAt = CarbonImmutable::now()->addMinutes($role->tokenLifetimeMinutes());
        $result->getToken()->forceFill(['expires_at' => $expiresAt])->save();

        return new IssuedToken(
            user: $user,
            accessToken: $result->accessToken,
            expiresAt: $expiresAt,
        );
    }

    /** Revokes only the token used for this request; other devices stay signed in. */
    public function signOut(User $user): void
    {
        $token = $user->currentAccessToken();

        // The contract only promises can()/cant(); a Bearer token is an AccessToken, which can be revoked.
        if ($token instanceof AccessToken) {
            $token->revoke();
        }
    }
}
