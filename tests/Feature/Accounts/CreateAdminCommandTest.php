<?php

namespace Tests\Feature\Accounts;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_admin(): void
    {
        $this->runCommand('Store Owner', 'Owner@Example.com', 'correct-horse-42', 'correct-horse-42')
            ->expectsOutput('Admin owner@example.com created.')
            ->assertSuccessful();

        $admin = User::where('email', 'owner@example.com')->sole();
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isCustomer());
        $this->assertSame(UserStatus::Active, $admin->status);
        $this->assertTrue(Hash::check('correct-horse-42', $admin->password));
    }

    public function test_it_rejects_an_email_that_is_already_used(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $this->runCommand('Store Owner', 'owner@example.com', 'correct-horse-42', 'correct-horse-42')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_it_rejects_the_email_of_a_soft_deleted_user(): void
    {
        User::factory()->create(['email' => 'owner@example.com'])->delete();

        $this->runCommand('Store Owner', 'owner@example.com', 'correct-horse-42', 'correct-horse-42')
            ->assertFailed();
    }

    public function test_it_rejects_a_weak_or_mismatched_password(): void
    {
        $this->runCommand('Store Owner', 'owner@example.com', 'short1', 'short1')->assertFailed();
        $this->runCommand('Store Owner', 'owner@example.com', 'correct-horse-42', 'different-horse-42')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    private function runCommand(string $name, string $email, string $password, string $confirmation)
    {
        return $this->artisan('admin:create')
            ->expectsQuestion('Name', $name)
            ->expectsQuestion('Email', $email)
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $confirmation);
    }
}
