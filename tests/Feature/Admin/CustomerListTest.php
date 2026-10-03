<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\CustomerAddress;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CustomerListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Passport::actingAs(User::factory()->admin()->create(), [Role::Admin->scope()]);
    }

    public function test_it_lists_only_customers_newest_first_with_pagination_meta(): void
    {
        $old = User::factory()->customerWithProfile()->create(['created_at' => Carbon::parse('2026-01-01')]);
        $new = User::factory()->customer()->create(['created_at' => Carbon::parse('2026-06-01')]);
        User::factory()->admin()->create();
        User::factory()->customer()->create()->delete();

        $this->getJson('/api/v1/admin/customers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.reference_id', $new->reference_id)
            ->assertJsonPath('data.0.profile_completed', false)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.1.reference_id', $old->reference_id)
            ->assertJsonPath('data.1.profile_completed', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 20);
    }

    public function test_rows_show_phone_and_default_address_city(): void
    {
        $customer = User::factory()->customerWithProfile()->create();
        CustomerAddress::factory()->for($customer->customerProfile)->create(['city' => 'Pune']);
        CustomerAddress::factory()->for($customer->customerProfile)->default()->create(['city' => 'Bengaluru']);

        $this->getJson('/api/v1/admin/customers')
            ->assertJsonPath('data.0.phone', $customer->customerProfile->phone)
            ->assertJsonPath('data.0.city', 'Bengaluru');
    }

    public function test_search_matches_name_email_and_phone(): void
    {
        $byName = User::factory()->customerWithProfile()->create(['name' => 'Ravi Kumar']);
        $byEmail = User::factory()->customer()->create(['email' => 'meera@candles.in']);
        $byPhone = User::factory()->customerWithProfile()->create();
        $byPhone->customerProfile->update(['phone' => '9000011111']);
        User::factory()->customerWithProfile()->create(['name' => 'Someone Else', 'email' => 'x@y.z']);

        $this->getJson('/api/v1/admin/customers?search=ravi')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference_id', $byName->reference_id);
        $this->getJson('/api/v1/admin/customers?search=meera@')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference_id', $byEmail->reference_id);
        $this->getJson('/api/v1/admin/customers?search=00011')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference_id', $byPhone->reference_id);
        $this->getJson('/api/v1/admin/customers?search=%25')->assertJsonCount(0, 'data');
    }

    public function test_sort_and_page_size(): void
    {
        User::factory()->customerWithProfile()->create(['name' => 'Zara']);
        User::factory()->customerWithProfile()->create(['name' => 'Anil']);
        $noName = User::factory()->customer()->create();

        $this->getJson('/api/v1/admin/customers?sort=name')
            ->assertJsonPath('data.0.name', 'Anil')
            ->assertJsonPath('data.1.name', 'Zara');

        $this->getJson('/api/v1/admin/customers?sort=name&per_page=2&page=2')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.reference_id', $noName->reference_id);
    }

    public function test_the_response_echoes_the_applied_filters_and_their_options(): void
    {
        $this->getJson('/api/v1/admin/customers?search=%20ravi%20&sort=name&per_page=5')
            ->assertOk()
            ->assertJsonPath('filters', ['search' => 'ravi', 'sort' => 'name', 'per_page' => 5])
            ->assertJsonPath('filter_options', ['sort' => ['newest', 'oldest', 'name']]);

        $this->getJson('/api/v1/admin/customers')
            ->assertJsonPath('filters', ['search' => null, 'sort' => 'newest', 'per_page' => 20]);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->getJson('/api/v1/admin/customers?sort=spent&per_page=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort', 'per_page']);
    }

    public function test_customers_cannot_list_customers(): void
    {
        Passport::actingAs(User::factory()->customer()->create(), [Role::Customer->scope()]);

        $this->getJson('/api/v1/admin/customers')->assertForbidden();
    }
}
