<?php

namespace Tests\Feature\Customer;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\CustomerAddress;
use App\Models\Media;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const CHECKOUT = '/api/v1/customer/checkout';

    private const LINKS = 'https://api.razorpay.test/v1/payment_links';

    private User $customer;

    private CustomerAddress $address;

    private Product $product;

    /** What the fake Razorpay answers instead of a created link: a failed response or a thrown exception. */
    private mixed $razorpayFailure = null;

    /** What the fake Razorpay answers when asked to cancel a link, instead of success. */
    private mixed $cancelFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'media-test']);
        Storage::fake('media-test');
        config([
            'services.razorpay.base_url' => 'https://api.razorpay.test/v1',
            'services.razorpay.key_id' => 'rzp_test_key',
            'services.razorpay.key_secret' => 'secret',
            'services.razorpay.callback_url' => 'https://shop.test/checkout/result',
            'services.razorpay.link_expiry_minutes' => 30,
        ]);
        $this->fakeRazorpay();

        $this->customer = User::factory()->customerWithProfile()->create(['name' => 'Asha Rao', 'email' => 'asha@example.com']);
        $this->customer->customerProfile->forceFill(['phone' => '+919876543210'])->save();
        $this->address = CustomerAddress::factory()->default()->create([
            'customer_profile_id' => $this->customer->customerProfile->id,
            'recipient_name' => 'Ravi Rao',
            'phone' => '+919000000000',
            'city' => 'Bengaluru',
        ]);
        Passport::actingAs($this->customer, [Role::Customer->scope()]);
        $this->product = Product::factory()->create(['name' => 'Amber & Sandalwood']);
    }

    private function fakeRazorpay(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::LINKS.'/*/cancel' => fn () => $this->cancelFailure ?? Http::response(['status' => 'cancelled'])]);
        Http::fake([self::LINKS => fn () => match (true) {
            $this->razorpayFailure instanceof \Throwable => throw $this->razorpayFailure,
            $this->razorpayFailure !== null => $this->razorpayFailure,
            default => null,
        } ?? Http::response([
            'id' => 'plink_'.Str::random(14),
            'short_url' => 'https://rzp.io/i/'.Str::random(8),
            'status' => 'created',
        ])]);
    }

    private function variant(array $attributes = []): ProductVariant
    {
        return ProductVariant::factory()->for($this->product)->create(['name' => 'Small', ...$attributes]);
    }

    private function add(ProductVariant $variant, int $quantity = 1): void
    {
        $this->postJson('/api/v1/customer/cart/items', ['product_variant_id' => $variant->reference_id, 'quantity' => $quantity])->assertOk();
    }

    private function checkout(?string $addressId = null)
    {
        return $this->postJson(self::CHECKOUT, ['address_id' => $addressId ?? $this->address->reference_id]);
    }

    public function test_checkout_creates_a_pending_order_and_returns_the_razorpay_link(): void
    {
        $this->freezeSecond();
        $variant = $this->variant(['sku' => 'AMB-S', 'original_price' => '1199.00', 'selling_price' => '899.50', 'stock' => 10]);
        $cover = Media::factory()->attachedTo($variant, 0)->create();
        $this->add($variant, 3);

        $response = $this->checkout()
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '2698.50')
            ->assertJsonPath('data.currency', 'INR')
            ->assertJsonPath('data.currency_symbol', '₹')
            ->assertJsonPath('data.expires_at', now()->addMinutes(30)->toIso8601String());

        $order = Order::sole();
        $payment = Payment::sole();
        $response->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.payment_number', $payment->payment_number)
            ->assertJsonPath('data.payment_url', $payment->payment_url);
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-[2-9A-HJ-NP-Z]{6}$/', $order->order_number);
        $this->assertStringStartsWith('https://rzp.io/i/', $payment->payment_url);

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->placed_at);
        $this->assertSame(['2698.50', '0.00', '0.00', '2698.50'], [$order->subtotal, $order->discount_amount, $order->shipping_amount, $order->total_amount]);
        $this->assertSame(['Asha Rao', 'asha@example.com', '+919876543210'], [$order->customer_name, $order->customer_email, $order->customer_phone]);
        $this->assertSame(['Ravi Rao', '+919000000000', 'Bengaluru'], [$order->recipient_name, $order->recipient_phone, $order->city]);

        $item = $order->items->sole();
        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertSame(['Amber & Sandalwood', 'Small', 'AMB-S', $cover->path], [$item->product_name, $item->variant_name, $item->sku, $item->variant_photo_path]);
        $this->assertSame(['1199.00', '899.50', 3, '2698.50', '2698.50'], [$item->original_unit_price, $item->selling_unit_price, $item->quantity, $item->subtotal, $item->total_amount]);

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('INR', $order->currency);
        $this->assertSame(['razorpay', '2698.50', 'INR'], [$payment->provider, $payment->amount, $payment->currency]);
        $this->assertStringStartsWith('plink_', $payment->payment_session_id);

        Http::assertSent(fn (Request $request) => $request->url() === self::LINKS
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_key:secret'))
            && $request['amount'] === 269850
            && $request['currency'] === 'INR'
            && $request['accept_partial'] === false
            && $request['reference_id'] === $payment->payment_number
            && $request['customer'] === ['name' => 'Asha Rao', 'email' => 'asha@example.com', 'contact' => '+919876543210']
            && $request['expire_by'] === now()->addMinutes(30)->getTimestamp()
            && $request['callback_url'] === "https://shop.test/checkout/result?order={$order->order_number}"
            && $request['callback_method'] === 'get'
            && $request['notes']['order_number'] === $order->order_number);

        // Nothing changes in the cart or stock until the payment is confirmed.
        $this->assertSame(10, $variant->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_the_total_counts_only_current_prices(): void
    {
        $variant = $this->variant(['original_price' => '500.00', 'selling_price' => '400.00', 'stock' => 5]);
        $this->add($variant, 2);
        $variant->update(['selling_price' => '450.00']);

        $this->checkout()->assertCreated()->assertJsonPath('data.total_amount', '900.00');
    }

    public function test_pressing_pay_again_for_the_same_cart_returns_the_same_link(): void
    {
        $this->add($this->variant(['stock' => 5]), 2);

        // 201 when a new order is created, 200 when the pending one is returned again.
        $first = $this->checkout()->assertCreated()->json('data');
        $second = $this->checkout()->assertOk()->json('data');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('orders', 1);
        Http::assertSentCount(1);
    }

    public function test_a_changed_cart_or_an_expired_link_starts_a_new_order(): void
    {
        $variant = $this->variant(['stock' => 5]);
        $this->add($variant, 1);
        $first = $this->checkout()->json('data.order_number');

        $this->add($variant, 1);
        $second = $this->checkout()->assertCreated()->json('data.order_number');
        $this->assertNotSame($first, $second);

        $this->travel(31)->minutes();
        $third = $this->checkout()->assertCreated()->json('data.order_number');
        $this->assertNotSame($second, $third);

        $this->assertDatabaseCount('orders', 3);
    }

    public function test_a_new_checkout_cancels_the_older_payable_link(): void
    {
        $variant = $this->variant(['stock' => 5]);
        $this->add($variant, 1);
        $older = $this->checkout()->assertCreated()->json('data');
        $olderLinkId = Payment::where('payment_number', $older['payment_number'])->value('payment_session_id');

        $this->add($variant, 1);
        $newer = $this->checkout()->assertCreated()->json('data');

        Http::assertSent(fn (Request $request) => $request->url() === self::LINKS."/{$olderLinkId}/cancel");
        $olderOrder = Order::where('order_number', $older['order_number'])->sole();
        $this->assertSame(OrderStatus::Cancelled, $olderOrder->status);
        $this->assertSame('Replaced by a newer checkout.', $olderOrder->cancellation_reason);
        $this->assertSame(PaymentStatus::Cancelled, $olderOrder->latestPayment->status);
        $this->assertSame(OrderStatus::Pending, Order::where('order_number', $newer['order_number'])->sole()->status);
    }

    public function test_an_older_link_razorpay_will_not_cancel_is_left_for_the_webhook(): void
    {
        $variant = $this->variant(['stock' => 5]);
        $this->add($variant, 1);
        $older = $this->checkout()->json('data.order_number');

        // e.g. it was paid a moment ago: Razorpay refuses to cancel a paid link.
        $this->cancelFailure = Http::response(['error' => ['description' => 'Payment link is already paid']], 400);
        $this->add($variant, 1);
        $this->checkout()->assertCreated();

        $this->assertSame(OrderStatus::Pending, Order::where('order_number', $older)->sole()->status);
    }

    public function test_a_checkout_still_waiting_for_razorpay_is_reported_as_in_progress(): void
    {
        $this->add($this->variant(['stock' => 5]));
        $this->razorpayFailure = Http::response(['error' => ['description' => 'down']], 500);
        $this->checkout()->assertServiceUnavailable();

        // Simulate a request that created its order and is still waiting for the link.
        $order = Order::sole();
        $order->forceFill(['status' => OrderStatus::Pending, 'cancelled_at' => null])->save();
        $order->payments->sole()->forceFill(['status' => PaymentStatus::Pending])->save();

        $this->checkout()->assertConflict();
    }

    public function test_when_razorpay_fails_the_order_is_cancelled_and_pay_can_be_pressed_again(): void
    {
        $this->add($this->variant(['stock' => 5]));
        $this->razorpayFailure = Http::response(['error' => ['description' => 'Authentication failed']], 401);

        $this->checkout()
            ->assertServiceUnavailable()
            ->assertJsonPath('message', "We couldn't start the payment. Please try again.");

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertStringContainsString('Authentication failed', $payment->failure_reason);
        $this->assertNotNull($payment->failed_at);
        $this->assertSame(OrderStatus::Cancelled, $payment->order->status);

        $this->razorpayFailure = null;
        $orderNumber = $this->checkout()->assertCreated()->json('data.order_number');
        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(PaymentStatus::Pending, Order::where('order_number', $orderNumber)->sole()->latestPayment->status);
        $this->assertSame(PaymentStatus::Failed, $payment->order->latestPayment->status);
    }

    public function test_an_unreachable_razorpay_is_a_503(): void
    {
        $this->add($this->variant(['stock' => 5]));
        $this->razorpayFailure = new ConnectionException('timed out');

        $this->checkout()->assertServiceUnavailable();
        $this->assertSame(PaymentStatus::Failed, Payment::sole()->status);
    }

    public function test_the_review_checks_still_apply(): void
    {
        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors(['cart' => 'Your cart is empty.']);

        $variant = $this->variant(['stock' => 5]);
        $this->add($variant, 3);
        $variant->update(['stock' => 2]);
        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors('items.0');

        $other = CustomerAddress::factory()->create(['customer_profile_id' => User::factory()->customerWithProfile()->create()->customerProfile->id]);
        $this->checkout($other->reference_id)->assertUnprocessable()->assertJsonValidationErrors('address_id');
        $this->postJson(self::CHECKOUT, [])->assertUnprocessable()->assertJsonValidationErrors('address_id');

        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_a_profile_is_required_and_only_customers_can_check_out(): void
    {
        Passport::actingAs(User::factory()->customer()->create(), [Role::Customer->scope()]);
        $this->checkout()->assertConflict()->assertJsonPath('message', 'Create your profile first.');

        Passport::actingAs(User::factory()->admin()->create(), [Role::Admin->scope()]);
        $this->checkout()->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->checkout()->assertUnauthorized();
    }
}
