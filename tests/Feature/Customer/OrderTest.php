<?php

namespace Tests\Feature\Customer;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\CustomerAddress;
use App\Models\Media;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'media-test']);
        Storage::fake('media-test');
        config(['services.razorpay.base_url' => 'https://api.razorpay.test/v1']);
        Http::fake(['https://api.razorpay.test/v1/payment_links' => fn () => Http::response(['id' => 'plink_'.Str::random(14), 'short_url' => 'https://rzp.io/i/abc'])]);

        $this->customer = User::factory()->customerWithProfile()->create(['name' => 'Asha Rao']);
        CustomerAddress::factory()->default()->create(['customer_profile_id' => $this->customer->customerProfile->id, 'city' => 'Bengaluru']);
        Passport::actingAs($this->customer, [Role::Customer->scope()]);

        $product = Product::factory()->create(['name' => 'Amber & Sandalwood']);
        $this->variant = ProductVariant::factory()->for($product)->create(['name' => 'Small', 'original_price' => '1199.00', 'selling_price' => '899.50', 'stock' => 10]);
    }

    /** Checks out the current cart and returns the order number. */
    private function placeOrder(int $quantity = 2): string
    {
        $this->postJson('/api/v1/customer/cart/items', ['product_variant_id' => $this->variant->reference_id, 'quantity' => $quantity])->assertOk();
        $addressId = $this->customer->customerProfile->addresses()->value('reference_id');

        return $this->postJson('/api/v1/customer/checkout', ['address_id' => $addressId])->assertCreated()->json('data.order_number');
    }

    /** What the webhook will do on payment: confirm the order and mark the payment paid. */
    private function markPlaced(string $orderNumber, string $placedAt): Order
    {
        $order = Order::where('order_number', $orderNumber)->sole();
        $order->forceFill(['status' => OrderStatus::Confirmed, 'placed_at' => $placedAt])->save();
        $order->latestPayment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => $placedAt])->save();

        return $order;
    }

    public function test_the_list_shows_placed_orders_newest_first(): void
    {
        $cover = Media::factory()->attachedTo($this->variant, 0)->create();
        $older = $this->placeOrder(2);
        $this->markPlaced($older, '2026-10-01 10:00:00');
        $newer = $this->placeOrder(1);
        $this->markPlaced($newer, '2026-10-02 10:00:00');

        $this->getJson('/api/v1/customer/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.order_number', $newer)
            ->assertJsonPath('data.1.order_number', $older)
            ->assertJsonPath('data.1.status', 'confirmed')
            ->assertJsonPath('data.1.payment_status', 'paid')
            ->assertJsonPath('data.1.total_amount', '1799.00')
            ->assertJsonPath('data.1.item_count', 2)
            ->assertJsonPath('data.1.line_count', 1)
            ->assertJsonPath('data.1.first_item', ['product_name' => 'Amber & Sandalwood', 'variant_name' => 'Small', 'photo_url' => $cover->url()])
            ->assertJsonPath('meta.total', 2);
    }

    public function test_pending_and_failed_checkouts_are_not_listed(): void
    {
        $pending = $this->placeOrder();
        $failed = $this->placeOrder(3);
        Order::where('order_number', $failed)->sole()->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();
        $placedThenCancelled = $this->placeOrder(4);
        $this->markPlaced($placedThenCancelled, '2026-10-02 10:00:00')->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();

        $this->getJson('/api/v1/customer/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', $placedThenCancelled)
            ->assertJsonPath('data.0.status', 'cancelled');

        // Still reachable by number for the result page.
        $this->getJson("/api/v1/customer/orders/{$pending}")->assertOk();
    }

    public function test_the_list_is_paginated_and_only_the_customers_own(): void
    {
        foreach (range(1, 3) as $quantity) {
            $this->markPlaced($this->placeOrder($quantity), "2026-10-0{$quantity} 10:00:00");
        }

        $this->getJson('/api/v1/customer/orders?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/customer/orders?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/customer/orders?per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');

        Passport::actingAs(User::factory()->customerWithProfile()->create(), [Role::Customer->scope()]);
        $this->getJson('/api/v1/customer/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_result_page_gets_the_pending_order_with_its_payment(): void
    {
        $cover = Media::factory()->attachedTo($this->variant, 0)->create();
        $orderNumber = $this->placeOrder(2);

        $this->getJson("/api/v1/customer/orders/{$orderNumber}")
            ->assertOk()
            ->assertJsonPath('data.order_number', $orderNumber)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.placed_at', null)
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.can_pay', true)
            ->assertJsonPath('data.payment.payment_url', 'https://rzp.io/i/abc')
            ->assertJsonPath('data.payment.amount', '1799.00')
            ->assertJsonPath('data.fare_breakup', ['subtotal' => '1799.00', 'discount_amount' => '0.00', 'shipping_amount' => '0.00', 'total_amount' => '1799.00'])
            ->assertJsonPath('data.customer.name', 'Asha Rao')
            ->assertJsonPath('data.shipping_address.city', 'Bengaluru')
            ->assertJsonPath('data.items.0.product_name', 'Amber & Sandalwood')
            ->assertJsonPath('data.items.0.variant_name', 'Small')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.selling_unit_price', '899.50')
            ->assertJsonPath('data.items.0.photo_url', $cover->url());
    }

    public function test_the_order_shows_its_snapshot_not_current_catalog_data(): void
    {
        $orderNumber = $this->placeOrder();
        $this->variant->update(['name' => 'Renamed', 'selling_price' => '999.00']);
        $this->variant->product->update(['name' => 'Renamed product']);

        $this->getJson("/api/v1/customer/orders/{$orderNumber}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_name', 'Amber & Sandalwood')
            ->assertJsonPath('data.items.0.selling_unit_price', '899.50');
    }

    public function test_an_expired_link_cannot_be_paid_again(): void
    {
        $orderNumber = $this->placeOrder();
        $this->travel(31)->minutes();

        $this->getJson("/api/v1/customer/orders/{$orderNumber}")
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.can_pay', false)
            ->assertJsonPath('data.payment.payment_url', null);
    }

    public function test_the_payment_status_is_the_newest_attempts(): void
    {
        $orderNumber = $this->placeOrder();
        $order = Order::where('order_number', $orderNumber)->sole();
        $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'The payment could not be started.'])->save();
        $order->latestPayment->forceFill(['status' => PaymentStatus::Failed, 'failed_at' => now()])->save();

        $this->getJson("/api/v1/customer/orders/{$orderNumber}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'The payment could not be started.')
            ->assertJsonPath('data.payment.status', 'failed')
            ->assertJsonPath('data.payment.can_pay', false);
    }

    public function test_another_customers_or_an_unknown_order_is_not_found(): void
    {
        $orderNumber = $this->placeOrder();

        $this->getJson('/api/v1/customer/orders/ORD-20261003-ZZZZZZ')->assertNotFound();
        $this->getJson('/api/v1/customer/orders/not-an-order')->assertNotFound();

        Passport::actingAs(User::factory()->customerWithProfile()->create(), [Role::Customer->scope()]);
        $this->getJson("/api/v1/customer/orders/{$orderNumber}")->assertNotFound();
    }

    public function test_signed_out_and_admins_are_refused(): void
    {
        $orderNumber = $this->placeOrder();

        Passport::actingAs(User::factory()->admin()->create(), [Role::Admin->scope()]);
        $this->getJson("/api/v1/customer/orders/{$orderNumber}")->assertForbidden();
        $this->getJson('/api/v1/customer/orders')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/customer/orders/{$orderNumber}")->assertUnauthorized();
    }
}
