<?php

namespace App\Enums;

/**
 * The two Spatie role names. There are no other roles. Each role name is also the Passport token
 * scope its tokens carry, so a customer token can never reach admin routes, and the reverse.
 */
enum Role: string
{
    case Admin = 'admin';
    case Customer = 'customer';

    /**
     * The only guard name roles are stored under (`roles.guard_name`), pinned on User::$guard_name.
     * It matches Laravel's default guard, so Spatie calls without a guard land on it too. Users still
     * sign in through the Passport `api` guard: role checks compare names, not guards.
     */
    public const GUARD = 'web';

    /** Passport token scope for this role. */
    public function scope(): string
    {
        return $this->value;
    }

    /** How long a token issued to this role stays valid. */
    public function tokenLifetimeMinutes(): int
    {
        return (int) config("auth.token_lifetimes.{$this->value}");
    }
}
