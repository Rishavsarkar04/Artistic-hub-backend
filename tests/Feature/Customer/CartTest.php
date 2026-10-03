<?php

namespace Tests\Feature\Customer;

use App\Enums\Role;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private const CART = '/api/v1/customer/cart';

    private const ITEMS = '/api/v1/customer/cart/items';

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'media-test']);
        Storage::fake('media-test');
        $this->signIn(User::factory()->customerWithProfile()->create());
    }

    private function signIn(User $customer): void
    {
        Passport::actingAs($customer, [Role::Customer->scope()]);
    }

    private function variant(array $attributes = [], ?Product $product = null): ProductVariant
    {
        return ProductVariant::factory()->for($product ?? Product::factory()->create())->create($attributes);
    }

    /** Adds a variant and returns its cart item's reference id. */
    private function add(ProductVariant $variant, int $quantity = 1): string
    {
        $items = $this->postJson(self::ITEMS, ['product_variant_id' => $variant->reference_id, 'quantity' => $quantity])->assertOk()->json('data.items');

        return collect($items)->firstWhere('product_variant.reference_id', $variant->reference_id)['reference_id'];
    }

    public function test_the_cart_is_empty_before_the_first_add(): void
    {
        $this->getJson(self::CART)
            ->assertOk()
            ->assertExactJson(['data' => [
                'items' => [],
                'item_count' => 0,
                'subtotal' => '0.00',
                'has_issues' => false,
                'fare_breakup' => ['mrp_total' => '0.00', 'discount' => '0.00', 'subtotal' => '0.00'],
            ]]);
    }

    public function test_adding_shows_the_item_with_current_prices_and_totals(): void
    {
        $product = Product::factory()->create(['name' => 'Amber & Sandalwood']);
        $variant = $this->variant(['name' => 'Small', 'original_price' => '1199.00', 'selling_price' => '899.50', 'stock' => 5], $product);
        $cover = Media::factory()->attachedTo($variant, 0)->create();

        $this->postJson(self::ITEMS, ['product_variant_id' => $variant->reference_id, 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.subtotal', '1799.00')
            ->assertJsonPath('data.items.0.original_subtotal', '2398.00')
            ->assertJsonPath('data.items.0.issue', null)
            ->assertJsonPath('data.items.0.product_variant.reference_id', $variant->reference_id)
            ->assertJsonPath('data.items.0.product_variant.product.name', 'Amber & Sandalwood')
            ->assertJsonPath('data.items.0.product_variant.selling_price', '899.50')
            ->assertJsonPath('data.items.0.product_variant.stock', 5)
            ->assertJsonPath('data.items.0.product_variant.cover_url', $cover->url())
            ->assertJsonPath('data.item_count', 2)
            ->assertJsonPath('data.subtotal', '1799.00')
            ->assertJsonPath('data.has_issues', false)
            ->assertJsonPath('data.fare_breakup', ['mrp_total' => '2398.00', 'discount' => '599.00', 'subtotal' => '1799.00']);
    }

    public function test_quantity_defaults_to_one_and_adding_again_increases_it(): void
    {
        $variant = $this->variant(['stock' => 10]);

        $this->postJson(self::ITEMS, ['product_variant_id' => $variant->reference_id])->assertOk()->assertJsonPath('data.items.0.quantity', 1);
        $this->postJson(self::ITEMS, ['product_variant_id' => $variant->reference_id, 'quantity' => 3])
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 4);

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseCount('carts', 1);
    }

    public function test_only_buyable_variants_can_be_added(): void
    {
        foreach ([
            $this->variant(['stock' => 0]),
            $this->variant(['is_active' => false]),
            $this->variant([], Product::factory()->inactive()->create()),
        ] as $variant) {
            $this->postJson(self::ITEMS, ['product_variant_id' => $variant->reference_id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['product_variant_id' => 'This item is not available.']);
        }

        $this->postJson(self::ITEMS, ['product_variant_id' => strtolower((string) Str::ulid())])->assertUnprocessable()->assertJsonValidationErrors('product_variant_id');
        $this->postJson(self::ITEMS, ['product_variant_id' => 'nope', 'quantity' => 0])->assertUnprocessable()->assertJsonValidationErrors(['product_variant_id', 'quantity']);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_the_cart_cannot_hold_more_than_the_stock(): void
    {
        $variant = $this->variant(['stock' => 3]);

        $this->postJson(self::ITEMS, ['product_variant_id' => $variant->reference_id, 'quantity' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'Only 3 left.']);

        $this->add($variant, 2);

        $this->postJson(self::ITEMS, ['product_variant_id' => $variant->reference_id, 'quantity' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'Only 3 left, and 2 already in your cart.']);
    }

    public function test_changing_the_quantity(): void
    {
        $variant = $this->variant(['stock' => 5, 'selling_price' => '100.00', 'original_price' => '100.00']);
        $item = $this->add($variant, 2);

        $this->patchJson(self::ITEMS."/{$item}", ['quantity' => 5])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 5)
            ->assertJsonPath('data.subtotal', '500.00');

        $this->patchJson(self::ITEMS."/{$item}", ['quantity' => 6])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'Only 5 left.']);

        $this->patchJson(self::ITEMS."/{$item}", ['quantity' => 0])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_an_item_that_can_no_longer_be_bought_stays_flagged_and_out_of_the_subtotal(): void
    {
        $fine = $this->variant(['stock' => 5, 'selling_price' => '100.00', 'original_price' => '100.00']);
        $turnedOff = $this->variant(['stock' => 5]);
        $soldOut = $this->variant(['stock' => 5]);
        $runningLow = $this->variant(['stock' => 5]);
        $this->add($fine);
        $this->add($turnedOff);
        $this->add($soldOut);
        $lowItem = $this->add($runningLow, 4);

        $turnedOff->update(['is_active' => false]);
        $soldOut->update(['stock' => 0]);
        $runningLow->update(['stock' => 2]);

        $this->getJson(self::CART)
            ->assertOk()
            ->assertJsonCount(4, 'data.items')
            ->assertJsonPath('data.items.0.issue', null)
            ->assertJsonPath('data.items.1.issue', 'unavailable')
            ->assertJsonPath('data.items.2.issue', 'out_of_stock')
            ->assertJsonPath('data.items.3.issue', 'not_enough_stock')
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.fare_breakup', ['mrp_total' => '100.00', 'discount' => '0.00', 'subtotal' => '100.00'])
            ->assertJsonPath('data.has_issues', true);

        // Lowering always works and clears the issue; raising an unavailable item does not.
        $this->patchJson(self::ITEMS."/{$lowItem}", ['quantity' => 2])->assertOk()->assertJsonPath('data.items.3.issue', null);
        $offItem = $this->getJson(self::CART)->json('data.items.1.reference_id');
        $this->patchJson(self::ITEMS."/{$offItem}", ['quantity' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'This item is not available.']);
    }

    public function test_removing_an_item(): void
    {
        $keep = $this->variant();
        $item = $this->add($this->variant());
        $this->add($keep);

        $this->deleteJson(self::ITEMS."/{$item}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product_variant.reference_id', $keep->reference_id);

        $this->deleteJson(self::ITEMS."/{$item}")->assertNotFound();
    }

    public function test_deleting_a_variant_removes_it_from_the_cart(): void
    {
        $variant = $this->variant();
        $this->add($variant);

        $variant->delete();

        $this->getJson(self::CART)->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_another_customers_item_is_not_found(): void
    {
        $item = $this->add($this->variant());

        $this->signIn(User::factory()->customerWithProfile()->create());

        $this->patchJson(self::ITEMS."/{$item}", ['quantity' => 1])->assertNotFound();
        $this->deleteJson(self::ITEMS."/{$item}")->assertNotFound();
        $this->getJson(self::CART)->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_a_profile_is_required(): void
    {
        $this->signIn(User::factory()->customer()->create());

        $this->getJson(self::CART)->assertConflict()->assertJsonPath('message', 'Create your profile first.');
        $this->postJson(self::ITEMS, ['product_variant_id' => $this->variant()->reference_id])->assertConflict();
    }

    public function test_signed_out_and_admins_are_refused(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson(self::CART)->assertUnauthorized();

        Passport::actingAs(User::factory()->admin()->create(), [Role::Admin->scope()]);
        $this->getJson(self::CART)->assertForbidden();
    }
}
