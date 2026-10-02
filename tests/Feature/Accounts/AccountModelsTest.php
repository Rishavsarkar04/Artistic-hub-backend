<?php

namespace Tests\Feature\Accounts;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class AccountModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_newly_registered_customer_has_no_name_and_no_profile(): void
    {
        $customer = User::factory()->customer()->create();

        $this->assertNull($customer->name);
        $this->assertTrue($customer->isCustomer());
        $this->assertFalse($customer->isAdmin());
        $this->assertFalse($customer->hasCompletedProfile());
    }

    public function test_a_customer_with_a_profile_has_completed_onboarding(): void
    {
        $customer = User::factory()->customerWithProfile()->create();

        $this->assertTrue($customer->hasCompletedProfile());
        $this->assertInstanceOf(CustomerProfile::class, $customer->customerProfile);
    }

    public function test_status_defaults_to_active_and_cannot_be_mass_assigned(): void
    {
        $user = User::create(['email' => 'a@example.com', 'password' => 'secret-password', 'status' => 'blocked']);

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
        $this->assertTrue($user->isActive());
    }

    public function test_blocked_users_are_not_active(): void
    {
        $user = User::factory()->status(UserStatus::Blocked)->create();

        $this->assertFalse($user->isActive());
    }

    public function test_profile_notes_are_internal(): void
    {
        $profile = CustomerProfile::factory()->create();
        $profile->fill(['notes' => 'should be ignored'])->save();
        $profile->forceFill(['notes' => 'internal'])->save();

        $this->assertSame('internal', $profile->fresh()->notes);
        $this->assertArrayNotHasKey('notes', $profile->fresh()->toArray());
    }

    public function test_address_ownership_and_default_flag_cannot_be_mass_assigned(): void
    {
        $address = CustomerAddress::factory()->create();
        $otherProfile = CustomerProfile::factory()->create();

        $address->fill(['customer_profile_id' => $otherProfile->id, 'is_default' => true])->save();

        $address->refresh();
        $this->assertNotSame($otherProfile->id, $address->customer_profile_id);
        $this->assertFalse($address->is_default);
    }

    public function test_default_address_relation_returns_the_default(): void
    {
        $profile = CustomerProfile::factory()->create();
        CustomerAddress::factory()->for($profile)->create();
        $default = CustomerAddress::factory()->for($profile)->default()->create();

        $this->assertTrue($profile->defaultAddress->is($default));
        $this->assertCount(2, $profile->addresses);
    }

    public function test_deleting_a_user_soft_deletes_their_profile_too(): void
    {
        $address = CustomerAddress::factory()->create();
        $profile = $address->customerProfile;
        $user = $profile->user;

        $user->delete();

        $this->assertSoftDeleted($user);
        $this->assertSoftDeleted($profile);
        $this->assertNull(User::where('email', $user->email)->first());
        $this->assertSame(0, CustomerProfile::count());
        // Still reachable for records that must survive, such as orders.
        $this->assertTrue(CustomerProfile::withTrashed()->find($profile->id)->addresses->contains($address));
    }

    public function test_a_soft_deleted_users_email_stays_reserved(): void
    {
        $user = User::factory()->create(['email' => 'taken@example.com']);
        $user->delete();

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['email' => 'taken@example.com']);
    }

    public function test_a_soft_deleted_user_can_be_restored(): void
    {
        $user = User::factory()->customer()->create();
        $user->delete();

        User::withTrashed()->find($user->id)->restore();

        $this->assertNotSoftDeleted($user);
        $this->assertTrue($user->fresh()->isCustomer());
    }

    public function test_restoring_a_user_restores_their_profile(): void
    {
        $user = User::factory()->customerWithProfile()->create();
        $profile = $user->customerProfile;
        $user->delete();

        User::withTrashed()->find($user->id)->restore();

        $this->assertNotSoftDeleted($profile);
        $this->assertTrue($user->fresh()->hasCompletedProfile());
    }

    public function test_deleting_an_admin_without_a_profile_works(): void
    {
        $admin = User::factory()->admin()->create();

        $admin->delete();

        $this->assertSoftDeleted($admin);
    }

    public function test_force_deleting_a_user_removes_their_profile_and_addresses(): void
    {
        $address = CustomerAddress::factory()->create();
        $user = $address->customerProfile->user;

        $user->forceDelete();

        $this->assertModelMissing($address);
        $this->assertDatabaseCount('customer_profiles', 0);
    }

    public function test_role_seeder_creates_exactly_the_two_roles_and_can_run_again(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertEqualsCanonicalizing(
            [Role::Admin->value, Role::Customer->value],
            RoleModel::pluck('name')->all(),
        );
    }
}
