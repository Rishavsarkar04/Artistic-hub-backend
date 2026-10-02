<?php

namespace App\Data;

use App\Models\User;
use Carbon\CarbonImmutable;

/** A newly issued personal access token. The plain token is only available at this moment. */
final readonly class IssuedToken
{
    public function __construct(
        public User $user,
        public string $accessToken,
        public CarbonImmutable $expiresAt,
    ) {}
}
