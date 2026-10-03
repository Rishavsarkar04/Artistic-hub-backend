<?php

namespace Tests\Feature\Webhooks;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Mail\OrderConfirmed;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

class RazorpayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = '/api/v1/webhooks/razorpay';

    private const SECRET = 'whsec_test';

    private User $customer;

    private ProductVariant $candle;

    private ProductVariant $diffuser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'media-test']);
        Storage::fake('media-test');
        Mail::fake();
        config(['services.razorpay.base_url' => 'https://api.razorpay.test/v1', 'services.razorpay.webhook_secret' => self::SECRET]);
        Http::preventStrayRequests();
        Http::fake(['https://api.razorpay.test/v1/payment_links/*/cancel' => Http::response(['status' => 'cancelled'])]);
        Http::fake(['https://api.razorpay.test/v1/payment_links' => fn () => Http::response(['id' => 'plink_'.Str::random(14), 'short_url' => 'https://rzp.io/i/abc'])]);

        $this->customer = User::factory()->customerWithProfile()->create(['email' => 'asha@example.com']);
        CustomerAddress::factory()->default()->create(['customer_profile_id' => $this->customer->customerProfile->id]);
        Passport::actingAs($this->customer, [Role::Customer->scope()]);

        $product = Product::factory()->create(['name' => 'Amber & Sandalwood']);
        $this->candle = ProductVariant::factory()->for($product)->create(['name' => 'Small', 'selling_price' => '899.50', 'original_price' => '1199.00', 'stock' => 10]);
        $this->diffuser = ProductVariant::factory()->for($product)->create(['name' => 'Diffuser', 'selling_price' => '450.00', 'original_price' => '450.00', 'stock' => 4]);
    }

    private function add(ProductVariant $variant, int $quantity): void
    {
        $this->postJson('/api/v1/customer/cart/items', ['product_variant_id' => $variant->reference_id, 'quantity' => $quantity])->assertOk();
    }

    /** Checks out the cart (3 candles + 1 diffuser = 3148.50 by default) and returns the pending order. */
    private function checkout(): Order
    {
        $addressId = $this->customer->customerProfile->addresses()->value('reference_id');
        $orderNumber = $this->postJson('/api/v1/customer/checkout', ['address_id' => $addressId])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $orderNumber)->with('latestPayment')->sole();
    }

    /** Sends a signed webhook exactly as Razorpay does: raw JSON body and its HMAC in the header. */
    private function webhook(array $body, ?string $secret = self::SECRET): TestResponse
    {
        $rawBody = json_encode($body);
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $headers['HTTP_X_RAZORPAY_SIGNATURE'] = hash_hmac('sha256', $rawBody, $secret);
        }

        return $this->call('POST', self::WEBHOOK, [], [], [], $headers, $rawBody);
    }

    private function paidEvent(Order $order, string $method = 'upi'): array
    {
        $payment = $order->latestPayment;

        return [
            'entity' => 'event',
            'event' => 'payment_link.paid',
            'contains' => ['payment_link', 'order', 'payment'],
            'payload' => [
                'payment_link' => ['entity' => ['id' => $payment->payment_session_id, 'reference_id' => $payment->payment_number, 'status' => 'paid']],
                'payment' => ['entity' => [
                    'id' => 'pay_'.Str::random(14),
                    'amount' => (int) bcmul($payment->amount, '100'),
                    'currency' => 'INR',
                    'status' => 'captured',
                    'method' => $method,
                ]],
            ],
        ];
    }

    private function linkEvent(Order $order, string $event): array
    {
        $payment = $order->latestPayment;

        return [
            'entity' => 'event',
            'event' => $event,
            'payload' => ['payment_link' => ['entity' => ['id' => $payment->payment_session_id, 'reference_id' => $payment->payment_number]]],
        ];
    }

    public function test_a_paid_link_confirms_the_order_once(): void
    {
        $this->add($this->candle, 3);
        $this->add($this->diffuser, 1);
        $order = $this->checkout();
        $this->freezeSecond();

        $event = $this->paidEvent($order);
        $this->webhook($event)->assertOk();

        $order->refresh()->load('latestPayment');
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertEquals(now(), $order->placed_at);
        $this->assertNull($order->review_reason);
        $payment = $order->latestPayment;
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(PaymentMethod::Upi, $payment->method);
        $this->assertSame($event['payload']['payment']['entity']['id'], $payment->transaction_id);
        $this->assertEquals(now(), $payment->paid_at);
        $this->assertSame([7, 3], [$this->candle->fresh()->stock, $this->diffuser->fresh()->stock]);
        $this->assertDatabaseCount('cart_items', 0);
        Mail::assertQueued(OrderConfirmed::class, fn (OrderConfirmed $mail) => $mail->hasTo('asha@example.com') && $mail->order->is($order));

        // Razorpay retries: the same event again changes nothing.
        $this->webhook($event)->assertOk();
        $this->assertSame([7, 3], [$this->candle->fresh()->stock, $this->diffuser->fresh()->stock]);
        Mail::assertQueuedCount(1);

        // The order now shows as placed.
        $this->getJson("/api/v1/customer/orders/{$order->order_number}")->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.payment.status', 'paid');
        $this->getJson('/api/v1/customer/orders')->assertJsonCount(1, 'data');
    }

    public function test_only_the_paid_quantities_leave_the_cart(): void
    {
        $this->add($this->candle, 2);
        $order = $this->checkout();
        $this->add($this->candle, 1);    // added after checkout
        $this->add($this->diffuser, 1);  // added after checkout

        $this->webhook($this->paidEvent($order))->assertOk();

        $this->getJson('/api/v1/customer/cart')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 1)
            ->assertJsonPath('data.items.1.product_variant.reference_id', $this->diffuser->reference_id);
    }

    public function test_a_payment_when_stock_ran_short_is_confirmed_and_flagged(): void
    {
        $this->add($this->candle, 3);
        $order = $this->checkout();
        $this->candle->update(['stock' => 1]); // sold elsewhere meanwhile (no reservation)

        $this->webhook($this->paidEvent($order))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(0, $this->candle->fresh()->stock);
        $this->assertStringContainsString('Amber & Sandalwood (Small) ordered 3, had 1', $order->review_reason);
        Mail::assertQueuedCount(1);
    }

    public function test_a_cancelled_older_order_paid_anyway_is_confirmed(): void
    {
        $this->add($this->candle, 1);
        $older = $this->checkout();
        $this->add($this->candle, 1);
        $this->checkout(); // cancels the older order and its link

        $this->assertSame(OrderStatus::Cancelled, $older->fresh()->status);
        $this->webhook($this->paidEvent($older))->assertOk();

        $older->refresh();
        $this->assertSame(OrderStatus::Confirmed, $older->status);
        $this->assertNull($older->cancelled_at);
        $this->assertNull($older->cancellation_reason);
    }

    public function test_an_expired_or_cancelled_link_cancels_the_pending_order(): void
    {
        $this->add($this->candle, 1);
        $order = $this->checkout();

        $this->webhook($this->linkEvent($order, 'payment_link.expired'))->assertOk();

        $order->refresh()->load('latestPayment');
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('The payment link expired.', $order->cancellation_reason);
        $this->assertSame(PaymentStatus::Cancelled, $order->latestPayment->status);
        $this->assertSame(10, $this->candle->fresh()->stock);
    }

    public function test_an_expiry_after_payment_changes_nothing(): void
    {
        $this->add($this->candle, 1);
        $order = $this->checkout();
        $this->webhook($this->paidEvent($order))->assertOk();

        $this->webhook($this->linkEvent($order, 'payment_link.expired'))->assertOk();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_a_bad_signature_is_refused_and_changes_nothing(): void
    {
        $this->add($this->candle, 1);
        $order = $this->checkout();

        $this->webhook($this->paidEvent($order), secret: 'wrong')->assertBadRequest();
        $this->webhook($this->paidEvent($order), secret: null)->assertBadRequest();

        config(['services.razorpay.webhook_secret' => null]);
        $this->webhook($this->paidEvent($order), secret: '')->assertBadRequest();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_unknown_links_and_events_are_acknowledged_and_ignored(): void
    {
        $this->add($this->candle, 1);
        $order = $this->checkout();

        $event = $this->paidEvent($order);
        $event['payload']['payment_link']['entity']['id'] = 'plink_someone_else';
        $this->webhook($event)->assertOk();

        $this->webhook(['event' => 'payment.authorized', 'payload' => []])->assertOk();
        $this->webhook(['event' => 'payment_link.paid', 'payload' => []])->assertOk();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_the_webhook_needs_no_sign_in(): void
    {
        $this->add($this->candle, 1);
        $order = $this->checkout();
        $this->app['auth']->forgetGuards();

        $this->webhook($this->paidEvent($order))->assertOk();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_the_confirmation_email_renders_the_order(): void
    {
        $this->add($this->candle, 2);
        $order = $this->checkout();
        $this->webhook($this->paidEvent($order))->assertOk();

        $mail = new OrderConfirmed($order->fresh());
        $mail->assertHasSubject("Your order {$order->order_number} is confirmed");
        $mail->assertSeeInHtml('Amber &amp; Sandalwood (Small)', false);
        $mail->assertSeeInHtml('₹1799.00');
        $mail->assertSeeInHtml("/account/orders/{$order->order_number}");
    }
}
