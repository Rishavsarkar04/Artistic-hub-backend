<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\IssuesRealTokens;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use IssuesRealTokens, RefreshDatabase;

    private const PASSWORD = '/api/v1/auth/password';

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuesRealTokens();
        $this->customer = User::factory()->customer()->create(['email' => 'asha@example.com', 'password' => 'OldPass123']);
    }

    private function signIn(string $password = 'OldPass123'): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => 'asha@example.com', 'password' => $password])->assertOk()->json('data.access_token');
    }

    private function change(string $token, array $body)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->putJson(self::PASSWORD, $body);
    }

    public function test_the_password_changes_and_other_sessions_are_signed_out(): void
    {
        $phone = $this->signIn();
        $laptop = $this->signIn();

        $this->change($laptop, ['current_password' => 'OldPass123', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])
            ->assertOk()
            ->assertExactJson(['message' => 'Your password has been changed. Other devices have been signed out.']);

        $this->assertTrue(Hash::check('NewPass456', $this->customer->fresh()->password));

        $this->app['auth']->forgetGuards();
        $this->withToken($laptop)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', ['email' => 'asha@example.com', 'password' => 'OldPass123'])->assertUnauthorized();
        $this->signIn('NewPass456');
    }

    public function test_a_wrong_current_password_is_refused(): void
    {
        $this->change($this->signIn(), ['current_password' => 'Wrong123', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password' => 'Your current password is incorrect.']);

        $this->assertTrue(Hash::check('OldPass123', $this->customer->fresh()->password));
    }

    public function test_the_new_password_is_validated(): void
    {
        $token = $this->signIn();

        $this->change($token, ['current_password' => 'OldPass123', 'password' => 'OldPass123', 'password_confirmation' => 'OldPass123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'Choose a password different from your current one.']);
        $this->change($token, ['current_password' => 'OldPass123', 'password' => 'short', 'password_confirmation' => 'other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
        $this->change($token, [])->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'password']);
    }

    public function test_attempts_are_rate_limited(): void
    {
        $token = $this->signIn();

        foreach (range(1, 6) as $attempt) {
            $this->change($token, ['current_password' => 'Wrong123', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])->assertUnprocessable();
        }

        $this->change($token, ['current_password' => 'OldPass123', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])->assertTooManyRequests();
    }

    public function test_signed_out_and_admins_are_refused(): void
    {
        $this->putJson(self::PASSWORD, [])->assertUnauthorized();

        User::factory()->admin()->create(['email' => 'admin@example.com', 'password' => 'AdminPass1']);
        $adminToken = $this->postJson('/api/v1/admin/auth/login', ['email' => 'admin@example.com', 'password' => 'AdminPass1'])->json('data.access_token');
        $this->change($adminToken, ['current_password' => 'AdminPass1', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])->assertForbidden();
    }
}
