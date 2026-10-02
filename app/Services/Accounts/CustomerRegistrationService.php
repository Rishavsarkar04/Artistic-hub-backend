<?php

namespace App\Services\Accounts;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CustomerRegistrationService
{
    /**
     * Creates an active customer with no name and no profile; the profile comes after the first
     * sign-in. Always the customer role, whatever the request contained.
     */
    public function register(string $email, string $password): User
    {
        return DB::transaction(function () use ($email, $password) {
            $customer = new User(['email' => $email, 'password' => $password]);
            $customer->status = UserStatus::Active;
            $customer->save();

            $customer->assignRole(Role::Customer);

            return $customer;
        });
    }
}
