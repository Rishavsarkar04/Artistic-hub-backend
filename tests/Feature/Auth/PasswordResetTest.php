<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\IssuesRealTokens;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use IssuesRealTokens, RefreshDatabase;

    private const NEUTRAL = "If an account exists for that email, we've sent a link to reset the password.";

    /** [forgot-password URL, reset-password URL, login URL, role] for each area. */
    private const AREAS = [
        'customer' => ['/api/v1/auth/forgot-password', '/api/v1/auth/reset-password', '/api/v1/auth/login', Role::Customer],
        'admin' => ['/api/v1/admin/auth/forgot-password', '/api/v1/admin/auth/reset-password', '/api/v1/admin/auth/login', Role::Admin],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuesRealTokens();
        Notification::fake();
        config(['app.frontend_url' => 'https://shop.test']);
    }

    private function user(Role $role): User
    {
        $factory = User::factory()->state(['email' => "{$role->value}@example.com", 'password' => 'OldPass123']);

        return ($role === Role::Admin ? $factory->admin() : $factory->customer())->create();
    }

    /** Requests a link and returns the token from the email that was sent. */
    private function requestToken(string $area, User $user): string
    {
        $this->postJson(self::AREAS[$area][0], ['email' => $user->email])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        return $token;
    }

    private function reset(string $area, string $email, string $token, string $password = 'NewPass456')
    {
        return $this->postJson(self::AREAS[$area][1], ['email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password]);
    }

    public function test_each_area_emails_a_link_to_its_own_reset_page(): void
    {
        foreach (['customer' => '/reset-password', 'admin' => '/admin/reset-password'] as $area => $page) {
            $user = $this->user(self::AREAS[$area][3]);

            $this->postJson(self::AREAS[$area][0], ['email' => $user->email])->assertOk()->assertExactJson(['message' => self::NEUTRAL]);

            Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user, $page) {
                $url = $notification->toMail($user)->actionUrl;

                return $url === "https://shop.test{$page}?token={$notification->token}&email=".urlencode($user->email);
            });
        }
    }

    public function test_the_answer_is_the_same_for_unknown_emails_and_the_other_role(): void
    {
        $admin = $this->user(Role::Admin);
        $customer = $this->user(Role::Customer);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->assertExactJson(['message' => self::NEUTRAL]);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $admin->email])->assertOk()->assertExactJson(['message' => self::NEUTRAL]);
        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $customer->email])->assertOk()->assertExactJson(['message' => self::NEUTRAL]);

        Notification::assertNothingSent();
    }

    public function test_a_customer_resets_the_password_and_the_old_one_stops_working(): void
    {
        $this->assertResetWorks('customer');
    }

    public function test_an_admin_resets_the_password_and_the_old_one_stops_working(): void
    {
        $this->assertResetWorks('admin');
    }

    private function assertResetWorks(string $area): void
    {
        [, , $login, $role] = self::AREAS[$area];
        $user = $this->user($role);
        $token = $this->requestToken($area, $user);

        $this->reset($area, $user->email, $token)
            ->assertOk()
            ->assertExactJson(['message' => 'Your password has been reset. Sign in with your new password.']);

        $this->assertTrue(Hash::check('NewPass456', $user->fresh()->password));
        $this->postJson($login, ['email' => $user->email, 'password' => 'OldPass123'])->assertUnauthorized();
        $this->postJson($login, ['email' => $user->email, 'password' => 'NewPass456'])->assertOk();
    }

    public function test_requests_are_rate_limited(): void
    {
        foreach (range(1, 6) as $attempt) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertTooManyRequests();
    }

    public function test_a_link_works_once(): void
    {
        $user = $this->user(Role::Customer);
        $token = $this->requestToken('customer', $user);

        $this->reset('customer', $user->email, $token)->assertOk();
        $this->reset('customer', $user->email, $token, 'Another789')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token' => 'This password reset link is invalid or has expired. Request a new one.']);
    }

    public function test_an_expired_link_is_refused(): void
    {
        $user = $this->user(Role::Customer);
        $token = $this->requestToken('customer', $user);

        $this->travel(61)->minutes();

        $this->reset('customer', $user->email, $token)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertTrue(Hash::check('OldPass123', $user->fresh()->password));
    }

    public function test_a_wrong_token_or_the_other_areas_endpoint_is_refused(): void
    {
        $customer = $this->user(Role::Customer);
        $token = $this->requestToken('customer', $customer);

        $this->reset('customer', $customer->email, 'not-the-token')->assertUnprocessable()->assertJsonValidationErrors('token');
        // A customer's valid token cannot be used on the admin endpoint.
        $this->reset('admin', $customer->email, $token)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->reset('customer', 'nobody@example.com', $token)->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('OldPass123', $customer->fresh()->password));
    }

    public function test_resetting_signs_out_every_session(): void
    {
        $user = $this->user(Role::Customer);
        $sessionToken = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'OldPass123'])->json('data.access_token');
        $this->withToken($sessionToken)->getJson('/api/v1/auth/me')->assertOk();

        $this->reset('customer', $user->email, $this->requestToken('customer', $user))->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($sessionToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_second_link_within_a_minute_is_not_sent(): void
    {
        $user = $this->user(Role::Customer);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->assertExactJson(['message' => self::NEUTRAL]);

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    }

    public function test_input_is_validated(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'a@example.com', 'token' => 'x', 'password' => 'short', 'password_confirmation' => 'other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
        $this->postJson('/api/v1/admin/auth/reset-password', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'token', 'password']);
    }
}
