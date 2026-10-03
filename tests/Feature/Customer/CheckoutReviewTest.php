<?php

namespace Tests\Feature\Customer;

use App\Enums\Role;
use App\Models\CustomerAddress;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CheckoutReviewTest extends TestCase
{
    use RefreshDatabase;

    private const REVIEW = '/api/v1/customer/checkout/review';

    private User $customer;

    private CustomerAddress $address;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'media-test']);
        Storage::fake('media-test');

        $this->customer = User::factory()->customerWithProfile()->create();
        $this->address = CustomerAddress::factory()->default()->create(['customer_profile_id' => $this->customer->customerProfile->id, 'city' => 'Bengaluru']);
        Passport::actingAs($this->customer, [Role::Customer->scope()]);
        $this->product = Product::factory()->create(['name' => 'Amber & Sandalwood']);
    }

    private function variant(array $attributes = []): ProductVariant
    {
        return ProductVariant::factory()->for($this->product)->create(['name' => 'Small', ...$attributes]);
    }

    private function add(ProductVariant $variant, int $quantity = 1): void
    {
        $this->postJson('/api/v1/customer/cart/items', ['product_variant_id' => $variant->reference_id, 'quantity' => $quantity])->assertOk();
    }

    private function review(?string $addressId = null)
    {
        return $this->getJson(self::REVIEW.'?address_id='.($addressId ?? $this->address->reference_id));
    }

    public function test_a_valid_cart_and_address_are_returned_for_the_review_page(): void
    {
        $this->add($this->variant(['original_price' => '1199.00', 'selling_price' => '899.00', 'stock' => 10]), 4);

        $this->review()
            ->assertOk()
            ->assertJsonPath('data.address.reference_id', $this->address->reference_id)
            ->assertJsonPath('data.address.city', 'Bengaluru')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 4)
            ->assertJsonPath('data.items.0.subtotal', '3596.00')
            ->assertJsonPath('data.item_count', 4)
            ->assertJsonPath('data.fare_breakup', ['currency' => 'INR', 'currency_symbol' => '₹', 'mrp_total' => '4796.00', 'discount' => '1200.00', 'subtotal' => '3596.00']);
    }

    public function test_the_review_uses_current_prices(): void
    {
        $variant = $this->variant(['original_price' => '1000.00', 'selling_price' => '900.00', 'stock' => 5]);
        $this->add($variant, 2);

        $variant->update(['selling_price' => '950.00']);

        $this->review()->assertOk()->assertJsonPath('data.fare_breakup.subtotal', '1900.00');
    }

    public function test_another_customers_address_is_rejected_like_a_missing_one(): void
    {
        $this->add($this->variant(['stock' => 5]));
        $theirs = CustomerAddress::factory()->create();

        $this->review($theirs->reference_id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address_id' => 'This address was not found. Choose another one.']);
        $this->review(strtolower((string) Str::ulid()))->assertUnprocessable()->assertJsonValidationErrors('address_id');
        $this->getJson(self::REVIEW)->assertUnprocessable()->assertJsonValidationErrors('address_id');
    }

    public function test_an_empty_cart_cannot_be_reviewed(): void
    {
        $this->review()->assertUnprocessable()->assertJsonValidationErrors(['cart' => 'Your cart is empty.']);
    }

    public function test_each_item_that_cannot_be_bought_is_named(): void
    {
        $fine = $this->variant(['stock' => 5]);
        $turnedOff = $this->variant(['name' => 'Medium', 'stock' => 5]);
        $soldOut = $this->variant(['name' => 'Large', 'stock' => 5]);
        $runningLow = $this->variant(['name' => 'Mini', 'stock' => 5]);
        foreach ([$fine, $turnedOff, $soldOut] as $variant) {
            $this->add($variant);
        }
        $this->add($runningLow, 4);

        $turnedOff->update(['is_active' => false]);
        $soldOut->update(['stock' => 0]);
        $runningLow->update(['stock' => 2]);

        $this->review()
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'items.1' => 'Amber & Sandalwood (Medium) is no longer available. Remove it to continue.',
                'items.2' => 'Amber & Sandalwood (Large) is out of stock. Remove it to continue.',
                'items.3' => 'Only 2 left of Amber & Sandalwood (Mini). Lower the quantity to continue.',
            ])
            ->assertJsonMissingValidationErrors('items.0');

        // Reviewing changes nothing: the items are all still in the cart.
        $this->getJson('/api/v1/customer/cart')->assertJsonCount(4, 'data.items');
    }

    public function test_a_profile_is_needed_and_only_customers_can_review(): void
    {
        Passport::actingAs(User::factory()->customer()->create(), [Role::Customer->scope()]);
        $this->review()->assertConflict();

        Passport::actingAs(User::factory()->admin()->create(), [Role::Admin->scope()]);
        $this->review()->assertForbidden();
    }
}
