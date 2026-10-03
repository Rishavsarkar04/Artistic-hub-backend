<?php

namespace Tests\Feature\Customer;

use App\Enums\Role;
use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = User::factory()->customerWithProfile()->create();
        Passport::actingAs($this->customer, [Role::Customer->scope()]);
    }

    /** @return array<string, mixed> */
    private function validAddress(array $overrides = []): array
    {
        return [
            'label' => 'work',
            'recipient_name' => 'Ravi Kumar',
            'phone' => '98765 43210',
            'address_line_1' => '12 MG Road',
            'address_line_2' => 'Floor 3',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'postal_code' => '560001',
            'country' => 'India',
            ...$overrides,
        ];
    }

    private function profileId(): int
    {
        return $this->customer->customerProfile->id;
    }

    public function test_the_first_address_becomes_the_default_and_later_ones_do_not(): void
    {
        $this->postJson('/api/v1/customer/addresses', $this->validAddress())
            ->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.phone', '9876543210')
            ->assertJsonPath('data.label', 'work');

        $this->postJson('/api/v1/customer/addresses', $this->validAddress(['label' => null]))
            ->assertCreated()
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.label', 'home');
    }

    public function test_a_new_address_can_be_created_as_the_default(): void
    {
        $old = CustomerAddress::factory()->default()->create(['customer_profile_id' => $this->profileId()]);

        $this->postJson('/api/v1/customer/addresses', $this->validAddress(['is_default' => true]))
            ->assertCreated()
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($old->fresh()->is_default);
        $this->assertSame(1, $this->customer->customerProfile->addresses()->where('is_default', true)->count());
    }

    public function test_updating_with_is_default_true_moves_the_default(): void
    {
        $old = CustomerAddress::factory()->default()->create(['customer_profile_id' => $this->profileId()]);
        $address = CustomerAddress::factory()->create(['customer_profile_id' => $this->profileId()]);

        $this->putJson("/api/v1/customer/addresses/{$address->reference_id}", $this->validAddress(['is_default' => true]))
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($old->fresh()->is_default);
        $this->assertSame(1, $this->customer->customerProfile->addresses()->where('is_default', true)->count());
    }

    public function test_the_default_cannot_be_turned_off_directly(): void
    {
        $default = CustomerAddress::factory()->default()->create(['customer_profile_id' => $this->profileId()]);

        $this->putJson("/api/v1/customer/addresses/{$default->reference_id}", $this->validAddress(['is_default' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_default');

        $this->assertTrue($default->fresh()->is_default);
    }

    public function test_updating_without_is_default_keeps_the_flag_as_it_was(): void
    {
        $default = CustomerAddress::factory()->default()->create(['customer_profile_id' => $this->profileId()]);
        $other = CustomerAddress::factory()->create(['customer_profile_id' => $this->profileId()]);

        $this->putJson("/api/v1/customer/addresses/{$other->reference_id}", $this->validAddress())->assertOk()->assertJsonPath('data.is_default', false);
        $this->assertTrue($default->fresh()->is_default);
    }

    public function test_addresses_are_listed_default_first(): void
    {
        $older = CustomerAddress::factory()->create(['customer_profile_id' => $this->profileId()]);
        $default = CustomerAddress::factory()->default()->create(['customer_profile_id' => $this->profileId()]);
        $newer = CustomerAddress::factory()->create(['customer_profile_id' => $this->profileId()]);
        CustomerAddress::factory()->create(); // another customer's

        $this->getJson('/api/v1/customer/addresses')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.reference_id', $default->reference_id)
            ->assertJsonPath('data.1.reference_id', $newer->reference_id)
            ->assertJsonPath('data.2.reference_id', $older->reference_id);
    }

    public function test_setting_a_new_default_leaves_exactly_one(): void
    {
        $first = CustomerAddress::factory()->default()->create(['customer_profile_id' => $this->profileId()]);
        $second = CustomerAddress::factory()->create(['customer_profile_id' => $this->profileId()]);

        $this->patchJson("/api/v1/customer/addresses/{$second->reference_id}/default")
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame(1, $this->customer->customerProfile->addresses()->where('is_default', true)->count());
    }

    public function test_a_customer_updates_their_address(): void
    {
        $address = CustomerAddress::factory()->default()->create(['customer_profile_id' => $this->profileId()]);

        $this->putJson("/api/v1/customer/addresses/{$address->reference_id}", $this->validAddress(['city' => 'Mysuru']))
            ->assertOk()
            ->assertJsonPath('data.city', 'Mysuru')
            ->assertJsonPath('data.is_default', true);
    }

    public function test_another_customers_address_is_not_found(): void
    {
        $theirs = CustomerAddress::factory()->default()->create();

        $this->putJson("/api/v1/customer/addresses/{$theirs->reference_id}", $this->validAddress())->assertNotFound();
        $this->patchJson("/api/v1/customer/addresses/{$theirs->reference_id}/default")->assertNotFound();

        $this->assertNotSame('Bengaluru', $theirs->fresh()->city);
        $this->assertTrue($theirs->fresh()->is_default);
    }

    public function test_invalid_address_fields_are_rejected(): void
    {
        $this->postJson('/api/v1/customer/addresses', ['label' => 'office', 'phone' => 'x', 'postal_code' => '56@001', 'is_default' => 'yes please'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['label', 'recipient_name', 'phone', 'address_line_1', 'city', 'state', 'postal_code', 'country', 'is_default']);
    }

    public function test_addresses_need_a_profile_first(): void
    {
        Passport::actingAs(User::factory()->customer()->create(), [Role::Customer->scope()]);

        $this->postJson('/api/v1/customer/addresses', $this->validAddress())
            ->assertConflict()
            ->assertJsonPath('message', 'Create your profile first.');
        $this->getJson('/api/v1/customer/addresses')->assertConflict();
    }
}
