<?php

namespace App\Console\Commands;

use App\Services\Accounts\AdminAccountService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Provisions an admin account. Credentials are typed in, never stored in files or seeders. */
#[Signature('admin:create')]
#[Description('Create an admin account')]
class CreateAdmin extends Command
{
    public function handle(AdminAccountService $adminAccountService): int
    {
        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email ? mb_strtolower(trim($email)) : $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = $adminAccountService->createAdmin($name, $email, $password);
        $this->info("Admin {$admin->email} created.");

        return self::SUCCESS;
    }
}
