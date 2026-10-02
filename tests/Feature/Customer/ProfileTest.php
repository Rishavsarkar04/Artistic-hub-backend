<?php

namespace Tests\Feature\Customer;

use App\Data\CustomerProfileData;
use App\Enums\Role;
use App\Models\User;
use App\Services\Accounts\CustomerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use LogicException;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCustomer(?User $customer = null): User
    {
        $customer ??= User::factory()->customer()->create();
        Passport::actingAs($customer, [Role::Customer->scope()]);

        return $customer;
    }

    /** @return array<string, mixed> */
    private function validProfile(array $overrides = []): array
    {
        return [
            'name' => 'Ravi Kumar',
            'phone' => '+91 98765-43210',
            'date_of_birth' => '1995-04-12',
            'gender' => 'male',
            ...$overrides,
        ];
    }

    public function test_a_customer_creates_their_profile_and_name(): void
    {
        $customer = $this->actingAsCustomer();

        $this->postJson('/api/v1/customer/profile', [...$this->validProfile(), 'notes' => 'vip', 'status' => 'blocked'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ravi Kumar')
            ->assertJsonPath('data.phone', '+919876543210')
            ->assertJsonPath('data.date_of_birth', '1995-04-12')
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.avatar_url', null)
            ->assertJsonMissingPath('data.notes');

        $customer->refresh();
        $this->assertSame('Ravi Kumar', $customer->name);
        $this->assertTrue($customer->isActive());
        $this->assertNull($customer->customerProfile->notes);
    }

    public function test_optional_fields_can_be_left_out(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/customer/profile', ['name' => 'Ravi', 'phone' => '9876543210'])
            ->assertCreated()
            ->assertJsonPath('data.date_of_birth', null)
            ->assertJsonPath('data.gender', null);
    }

    public function test_a_second_profile_is_refused(): void
    {
        $this->actingAsCustomer(User::factory()->customerWithProfile()->create());

        $this->postJson('/api/v1/customer/profile', $this->validProfile())
            ->assertConflict()
            ->assertJsonPath('message', 'Your profile already exists. Update it instead.');
        $this->assertDatabaseCount('customer_profiles', 1);
    }

    public function test_invalid_profile_fields_are_rejected(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/customer/profile', [
            'name' => '',
            'phone' => 'call me',
            'date_of_birth' => '2999-01-01',
            'gender' => 'prefer_not_to_say',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone', 'date_of_birth', 'gender']);
    }

    public function test_reading_the_profile_before_it_exists_returns_409(): void
    {
        $this->actingAsCustomer();

        $this->getJson('/api/v1/customer/profile')
            ->assertConflict()
            ->assertJsonPath('message', 'Create your profile first.');
    }

    public function test_a_customer_reads_and_updates_their_profile(): void
    {
        $this->actingAsCustomer(User::factory()->customerWithProfile()->create(['name' => 'Old Name']));

        $this->getJson('/api/v1/customer/profile')->assertOk()->assertJsonPath('data.name', 'Old Name');

        $this->putJson('/api/v1/customer/profile', $this->validProfile(['name' => 'New Name', 'gender' => null]))
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.gender', null);
    }

    public function test_updating_a_missing_profile_returns_409(): void
    {
        $this->actingAsCustomer();

        $this->putJson('/api/v1/customer/profile', $this->validProfile())->assertConflict();
    }

    public function test_me_includes_the_profile_once_it_exists(): void
    {
        $customer = $this->actingAsCustomer();

        $this->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.profile_completed', false)
            ->assertJsonPath('data.profile', null);

        $this->postJson('/api/v1/customer/profile', $this->validProfile())->assertCreated();
        $customer->unsetRelation('customerProfile');

        $this->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.profile_completed', true)
            ->assertJsonPath('data.name', 'Ravi Kumar')
            ->assertJsonPath('data.profile.phone', '+919876543210');
    }

    public function test_admins_cannot_use_customer_profile_routes(): void
    {
        $admin = User::factory()->admin()->create();
        Passport::actingAs($admin, [Role::Admin->scope()]);

        $this->postJson('/api/v1/customer/profile', $this->validProfile())->assertForbidden();
    }

    public function test_the_service_never_gives_an_admin_a_profile(): void
    {
        $admin = User::factory()->admin()->create();

        $this->expectException(LogicException::class);
        app(CustomerProfileService::class)->createProfile($admin, new CustomerProfileData('Asha', '9876543210', null, null));
    }
}
