<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Laravel\Passport\Token;
use Tests\Concerns\IssuesRealTokens;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use IssuesRealTokens, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuesRealTokens();
    }

    private function signIn(string $email = 'asha@shop.in'): string
    {
        return $this->postJson('/api/v1/admin/auth/login', ['email' => $email, 'password' => 'candles123'])
            ->assertOk()
            ->json('data.access_token');
    }

    public function test_an_admin_signs_in_and_reads_their_account(): void
    {
        User::factory()->admin()->create(['name' => 'Asha', 'email' => 'asha@shop.in', 'password' => 'candles123']);

        $this->getJson('/api/v1/admin/auth/me', ['Authorization' => 'Bearer '.$this->signIn()])
            ->assertOk()
            ->assertJsonPath('data.name', 'Asha')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonMissingPath('data.profile_completed');
    }

    public function test_customers_cannot_sign_in_as_admin(): void
    {
        User::factory()->customer()->create(['email' => 'ravi@mail.com', 'password' => 'candles123']);

        $this->postJson('/api/v1/admin/auth/login', ['email' => 'ravi@mail.com', 'password' => 'candles123'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'The email or password is incorrect.']);
    }

    public function test_an_admin_token_cannot_reach_customer_routes(): void
    {
        User::factory()->admin()->create(['email' => 'asha@shop.in', 'password' => 'candles123']);

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$this->signIn()])->assertForbidden();
    }

    public function test_admin_tokens_expire_after_the_admin_lifetime(): void
    {
        config(['auth.token_lifetimes.admin' => 60]);
        User::factory()->admin()->create(['email' => 'asha@shop.in', 'password' => 'candles123']);
        $headers = ['Authorization' => 'Bearer '.$this->signIn()];

        $this->getJson('/api/v1/admin/auth/me', $headers)->assertOk();

        Date::setTestNow(now()->addMinutes(61));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/auth/me', $headers)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Your session has expired. Please sign in again.');
        Date::setTestNow();
    }

    public function test_the_tokens_real_expiry_is_saved_and_returned(): void
    {
        config(['auth.token_lifetimes.admin' => 60]);
        User::factory()->admin()->create(['email' => 'asha@shop.in', 'password' => 'candles123']);

        $response = $this->postJson('/api/v1/admin/auth/login', ['email' => 'asha@shop.in', 'password' => 'candles123']);

        $stored = Token::sole()->expires_at;
        $this->assertTrue($stored->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->assertSame($stored->toIso8601String(), $response->json('data.expires_at'));
    }

    public function test_logout_revokes_the_admin_token(): void
    {
        User::factory()->admin()->create(['email' => 'asha@shop.in', 'password' => 'candles123']);
        $headers = ['Authorization' => 'Bearer '.$this->signIn()];

        $this->postJson('/api/v1/admin/auth/logout', [], $headers)->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/auth/me', $headers)->assertUnauthorized();
    }
}
