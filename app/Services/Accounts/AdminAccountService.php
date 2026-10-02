<?php

namespace App\Services\Accounts;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as RoleModel;

final class AdminAccountService
{
    /** Creates an active admin. There is no public admin registration; this is only called from the console. */
    public function createAdmin(string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password) {
            $admin = new User([
                'name' => $name,
                'email' => mb_strtolower(trim($email)),
                'password' => $password,
            ]);
            $admin->status = UserStatus::Active;
            $admin->save();

            RoleModel::findOrCreate(Role::Admin->value, Role::GUARD);
            $admin->assignRole(Role::Admin);

            return $admin;
        });
    }
}
