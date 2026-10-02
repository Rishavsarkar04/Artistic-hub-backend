<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Concerns\IssuesRealTokens;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use IssuesRealTokens, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuesRealTokens();
    }

    public function test_registration_creates_an_active_customer_with_no_name_and_no_token(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => ' Ravi@Mail.com ',
            'password' => 'candles123',
            'password_confirmation' => 'candles123',
            'role' => 'admin',
            'status' => 'blocked',
            'name' => 'Sneaky',
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ravi@mail.com')
            ->assertJsonPath('data.name', null)
            ->assertJsonPath('data.role', 'customer')
            ->assertJsonPath('data.profile_completed', false)
            ->assertJsonMissingPath('access_token');

        $customer = User::where('email', 'ravi@mail.com')->sole();
        $this->assertTrue($customer->isCustomer());
        $this->assertFalse($customer->isAdmin());
        $this->assertSame(UserStatus::Active, $customer->status);
        $this->assertNull($customer->name);
    }

    public function test_registration_rejects_a_taken_email_including_soft_deleted_users(): void
    {
        User::factory()->create(['email' => 'ravi@mail.com'])->delete();

        $this->postJson('/api/v1/auth/register', [
            'email' => 'ravi@mail.com', 'password' => 'candles123', 'password_confirmation' => 'candles123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_registration_requires_a_confirmed_password_with_letters_and_numbers(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'a@mail.com', 'password' => 'short', 'password_confirmation' => 'other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_a_customer_signs_in_and_gets_a_customer_scoped_token(): void
    {
        User::factory()->customer()->create(['email' => 'ravi@mail.com', 'password' => 'candles123']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'RAVI@mail.com', 'password' => 'candles123'])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'ravi@mail.com');

        $token = $response->json('data.access_token');
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.email', 'ravi@mail.com')
            ->assertJsonPath('data.profile_completed', false);
    }

    public function test_wrong_password_unknown_email_and_admin_accounts_get_the_same_error(): void
    {
        User::factory()->customer()->create(['email' => 'ravi@mail.com', 'password' => 'candles123']);
        User::factory()->admin()->create(['email' => 'asha@shop.in', 'password' => 'candles123']);

        foreach ([
            ['ravi@mail.com', 'wrong-pass1'],
            ['nobody@mail.com', 'candles123'],
            ['asha@shop.in', 'candles123'],
        ] as [$email, $password]) {
            $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'These credentials do not match our records.']);
        }
    }

    public function test_inactive_and_deleted_customers_cannot_sign_in(): void
    {
        User::factory()->customer()->status(UserStatus::Blocked)->create(['email' => 'blocked@mail.com', 'password' => 'candles123']);
        User::factory()->customer()->create(['email' => 'gone@mail.com', 'password' => 'candles123'])->delete();

        $this->postJson('/api/v1/auth/login', ['email' => 'blocked@mail.com', 'password' => 'candles123'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is not active.');
        $this->postJson('/api/v1/auth/login', ['email' => 'gone@mail.com', 'password' => 'candles123'])
            ->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        User::factory()->customer()->create(['email' => 'ravi@mail.com', 'password' => 'candles123']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'ravi@mail.com', 'password' => 'candles123'])->json('data.access_token');
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->postJson('/api/v1/auth/logout', [], $headers)->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $headers)->assertUnauthorized();
    }

    public function test_a_customer_blocked_after_signing_in_is_locked_out(): void
    {
        $customer = User::factory()->customer()->create(['email' => 'ravi@mail.com', 'password' => 'candles123']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'ravi@mail.com', 'password' => 'candles123'])->json('data.access_token');

        $customer->forceFill(['status' => UserStatus::Blocked])->save();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertForbidden();
    }

    public function test_a_customer_token_cannot_reach_admin_routes(): void
    {
        User::factory()->customer()->create(['email' => 'ravi@mail.com', 'password' => 'candles123']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'ravi@mail.com', 'password' => 'candles123'])->json('data.access_token');

        $this->getJson('/api/v1/admin/auth/me', ['Authorization' => "Bearer {$token}"])->assertForbidden();
    }

    public function test_customer_tokens_expire_after_the_customer_lifetime(): void
    {
        config(['auth.token_lifetimes.customer' => 30]);
        User::factory()->customer()->create(['email' => 'ravi@mail.com', 'password' => 'candles123']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'ravi@mail.com', 'password' => 'candles123'])->json('data.access_token');

        Date::setTestNow(now()->addMinutes(31));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
        Date::setTestNow();
    }

    public function test_signed_in_routes_need_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->get('/api/v1/auth/me')->assertUnauthorized();
    }
}
