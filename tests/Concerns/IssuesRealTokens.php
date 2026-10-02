<?php

namespace Tests\Concerns;

use Database\Seeders\RoleSeeder;
use Laravel\Passport\ClientRepository;

/**
 * For tests that sign in through the real endpoints: seeds the roles, and creates the personal
 * access client Passport needs to issue tokens (with the keys from `php artisan passport:keys`).
 */
trait IssuesRealTokens
{
    protected function setUpIssuesRealTokens(): void
    {
        $this->seed(RoleSeeder::class);
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test personal access client', 'users');
    }
}
