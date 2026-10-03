<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Support\OrderNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private const ORDERS = '/api/v1/admin/orders';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name' => 'Meera Admin']);
        Passport::actingAs($this->admin, [Role::Admin->scope()]);
    }

    /** An order as the checkout and webhook leave it; placed (confirmed and paid) unless told otherwise. */
    private function order(User $customer, array $attributes = [], array $quantities = [1]): Order
    {
        $placed = ($attributes['status'] ?? OrderStatus::Confirmed) !== OrderStatus::Pending;

        $order = new Order;
        $order->forceFill([
            'customer_profile_id' => $customer->customerProfile->id,
            'order_number' => OrderNumber::unique(now(), fn (string $orderNumber) => Order::where('order_number', $orderNumber)->exists()),
            'status' => OrderStatus::Confirmed,
            'subtotal' => '100.00',
            'total_amount' => '100.00',
            'currency' => 'INR',
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '+919876543210',
            'recipient_name' => $customer->name,
            'recipient_phone' => '+919876543210',
            'address_line_1' => '1 MG Road',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'postal_code' => '560001',
            'country' => 'India',
            'placed_at' => $placed ? now() : null,
            ...$attributes,
        ])->save();

        foreach ($quantities as $quantity) {
            $item = new OrderItem;
            $item->forceFill([
                'product_name' => 'Amber & Sandalwood', 'variant_name' => 'Small', 'sku' => 'AMB-S',
                'original_unit_price' => '100.00', 'selling_unit_price' => '100.00', 'quantity' => $quantity,
                'subtotal' => '100.00', 'total_amount' => '100.00',
            ]);
            $order->items()->save($item);
        }

        $payment = new Payment;
        $payment->forceFill([
            'payment_number' => 'PAY'.strtoupper((string) Str::ulid()), 'provider' => 'razorpay', 'amount' => $order->total_amount, 'currency' => 'INR',
            'status' => $placed ? PaymentStatus::Paid : PaymentStatus::Pending,
        ]);
        $order->payments()->save($payment);

        return $order;
    }

    public function test_placed_orders_are_listed_newest_first_with_their_summary(): void
    {
        $asha = User::factory()->customerWithProfile()->create(['name' => 'Asha Rao', 'email' => 'asha@example.com']);
        $older = $this->order($asha, ['placed_at' => '2026-10-01 10:00:00', 'total_amount' => '1799.00'], quantities: [2, 3]);
        $newer = $this->order($asha, ['placed_at' => '2026-10-02 10:00:00', 'review_reason' => 'Paid when stock was short']);

        $this->getJson(self::ORDERS)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.order_number', $newer->order_number)
            ->assertJsonPath('data.0.needs_review', true)
            ->assertJsonPath('data.1.order_number', $older->order_number)
            ->assertJsonPath('data.1.status', 'confirmed')
            ->assertJsonPath('data.1.payment_status', 'paid')
            ->assertJsonPath('data.1.customer', ['reference_id' => $asha->reference_id, 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '+919876543210'])
            ->assertJsonPath('data.1.city', 'Bengaluru')
            ->assertJsonPath('data.1.item_count', 5)
            ->assertJsonPath('data.1.total_amount', '1799.00')
            ->assertJsonPath('data.1.currency', 'INR')
            ->assertJsonPath('data.1.currency_symbol', '₹')
            ->assertJsonPath('data.1.needs_review', false)
            ->assertJsonPath('filters', ['search' => null, 'status' => null, 'customer_id' => null, 'sort' => 'newest', 'per_page' => 20])
            ->assertJsonPath('filter_options.status', ['confirmed', 'processing', 'completed', 'cancelled'])
            ->assertJsonPath('filter_options.sort', ['newest', 'oldest', 'total_high', 'total_low']);
    }

    public function test_pending_and_failed_checkouts_are_not_listed(): void
    {
        $customer = User::factory()->customerWithProfile()->create();
        $placed = $this->order($customer);
        $this->order($customer, ['status' => OrderStatus::Pending]);
        $this->order($customer, ['status' => OrderStatus::Cancelled, 'placed_at' => null]); // payment never started
        $placedThenCancelled = $this->order($customer, ['status' => OrderStatus::Cancelled, 'placed_at' => now()->subDay()]);

        $this->getJson(self::ORDERS)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.order_number', $placed->order_number)
            ->assertJsonPath('data.1.order_number', $placedThenCancelled->order_number);
    }

    public function test_search_matches_order_number_customer_and_city(): void
    {
        $asha = User::factory()->customerWithProfile()->create(['name' => 'Asha Rao', 'email' => 'asha@example.com']);
        $ravi = User::factory()->customerWithProfile()->create(['name' => 'Ravi Kumar', 'email' => 'ravi@example.com']);
        $ashaOrder = $this->order($asha, ['city' => 'Bengaluru']);
        $raviOrder = $this->order($ravi, ['city' => 'Pune']);

        foreach ([$ashaOrder->order_number, 'Asha', 'asha@example', 'bengal'] as $search) {
            $this->getJson(self::ORDERS.'?search='.urlencode($search))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_number', $ashaOrder->order_number);
        }
        $this->getJson(self::ORDERS.'?search=pune')->assertJsonPath('data.0.order_number', $raviOrder->order_number);
        $this->getJson(self::ORDERS.'?search=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filtering_by_status_and_customer(): void
    {
        $asha = User::factory()->customerWithProfile()->create();
        $ravi = User::factory()->customerWithProfile()->create();
        $processing = $this->order($asha, ['status' => OrderStatus::Processing]);
        $this->order($asha);
        $raviOrder = $this->order($ravi);

        $this->getJson(self::ORDERS.'?status=processing')->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_number', $processing->order_number);
        $this->getJson(self::ORDERS."?customer_id={$ravi->reference_id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', $raviOrder->order_number)
            ->assertJsonPath('filters.customer_id', $ravi->reference_id);

        $this->getJson(self::ORDERS.'?status=pending')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson(self::ORDERS.'?customer_id=nope&sort=cheapest&per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'sort', 'per_page']);
    }

    public function test_sorting_by_date_and_total(): void
    {
        $customer = User::factory()->customerWithProfile()->create();
        $cheapOld = $this->order($customer, ['placed_at' => '2026-10-01 10:00:00', 'total_amount' => '200.00']);
        $dearNew = $this->order($customer, ['placed_at' => '2026-10-03 10:00:00', 'total_amount' => '1500.00']);
        $midMiddle = $this->order($customer, ['placed_at' => '2026-10-02 10:00:00', 'total_amount' => '899.50']);

        $order = fn (string $sort) => array_column($this->getJson(self::ORDERS."?sort={$sort}")->assertOk()->json('data'), 'order_number');

        $this->assertSame([$cheapOld->order_number, $midMiddle->order_number, $dearNew->order_number], $order('oldest'));
        $this->assertSame([$dearNew->order_number, $midMiddle->order_number, $cheapOld->order_number], $order('newest'));
        $this->assertSame([$dearNew->order_number, $midMiddle->order_number, $cheapOld->order_number], $order('total_high'));
        $this->assertSame([$cheapOld->order_number, $midMiddle->order_number, $dearNew->order_number], $order('total_low'));
    }

    public function test_a_deleted_customers_orders_stay_listed(): void
    {
        $customer = User::factory()->customerWithProfile()->create(['name' => 'Asha Rao']);
        $order = $this->order($customer);
        $customer->delete();

        $this->getJson(self::ORDERS)
            ->assertJsonPath('data.0.order_number', $order->order_number)
            ->assertJsonPath('data.0.customer.name', 'Asha Rao')
            ->assertJsonPath('data.0.customer.reference_id', $customer->reference_id);
        $this->getJson(self::ORDERS."?customer_id={$customer->reference_id}")->assertJsonCount(1, 'data');
    }

    public function test_pagination(): void
    {
        $customer = User::factory()->customerWithProfile()->create();
        foreach (range(1, 3) as $day) {
            $this->order($customer, ['placed_at' => "2026-10-0{$day} 10:00:00"]);
        }

        $this->getJson(self::ORDERS.'?per_page=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $this->getJson(self::ORDERS.'?per_page=2&page=2')->assertJsonCount(1, 'data');
    }

    public function test_the_details_show_the_snapshot_review_reason_and_every_payment(): void
    {
        $asha = User::factory()->customerWithProfile()->create(['name' => 'Asha Rao', 'email' => 'asha@example.com']);
        $order = $this->order($asha, ['total_amount' => '1799.00', 'review_reason' => 'Paid when stock was short'], quantities: [2]);
        // An earlier attempt that Razorpay refused, before the paid one.
        $failed = new Payment;
        $failed->forceFill([
            'payment_number' => 'PAY'.strtoupper((string) Str::ulid()), 'provider' => 'razorpay', 'amount' => '1799.00', 'currency' => 'INR',
            'status' => PaymentStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'Razorpay refused the payment link (HTTP 401)',
        ]);
        $order->payments()->save($failed);
        $order->payments()->oldest('id')->first()->forceFill(['transaction_id' => 'pay_X', 'payment_session_id' => 'plink_A', 'paid_at' => now()])->save();

        $this->getJson(self::ORDERS."/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.review_reason', 'Paid when stock was short')
            ->assertJsonPath('data.customer', ['reference_id' => $asha->reference_id, 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '+919876543210'])
            ->assertJsonPath('data.shipping_address.city', 'Bengaluru')
            ->assertJsonPath('data.fare_breakup.total_amount', '1799.00')
            ->assertJsonPath('data.items.0.product_name', 'Amber & Sandalwood')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.payment_status', 'failed')
            ->assertJsonPath('data.payment.payment_number', $failed->payment_number)
            ->assertJsonPath('data.payment.failure_reason', 'Razorpay refused the payment link (HTTP 401)')
            ->assertJsonCount(1, 'data.payment_history')
            ->assertJsonPath('data.payment_history.0.status', 'paid')
            ->assertJsonPath('data.payment_history.0.transaction_id', 'pay_X')
            ->assertJsonPath('data.payment_history.0.payment_link_id', 'plink_A')
            ->assertJsonMissingPath('data.payment.gateway_response')
            ->assertJsonCount(8, 'tracking_provider_options')
            ->assertJsonPath('tracking_provider_options.1', ['value' => 'bluedart', 'label' => 'Blue Dart']);
    }

    public function test_the_details_open_any_status_and_unknown_numbers_are_not_found(): void
    {
        $customer = User::factory()->customerWithProfile()->create();
        $pending = $this->order($customer, ['status' => OrderStatus::Pending]);

        $this->getJson(self::ORDERS."/{$pending->order_number}")->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.placed_at', null)
            ->assertJsonPath('data.payment.status', 'pending')->assertJsonCount(0, 'data.payment_history');
        $this->getJson(self::ORDERS.'/ORD-20261003-ZZZZZZ')->assertNotFound();
        $this->getJson(self::ORDERS.'/not-an-order')->assertNotFound();
    }

    public function test_saving_tracking_records_who_and_when_and_completes_the_order(): void
    {
        $customer = User::factory()->customerWithProfile()->create();
        $order = $this->order($customer);
        $this->freezeSecond();

        $this->patchJson(self::ORDERS."/{$order->order_number}/tracking", ['tracking_provider' => 'delhivery', 'tracking_number' => ' 0042981277 '])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_at', now()->toIso8601String())
            ->assertJsonPath('tracking_provider_options.0', ['value' => 'delhivery', 'label' => 'Delhivery'])
            ->assertJsonPath('data.tracking', [
                'provider' => 'delhivery',
                'provider_name' => 'Delhivery',
                'number' => '0042981277',
                'updated_at' => now()->toIso8601String(),
                'updated_by' => ['reference_id' => $this->admin->reference_id, 'name' => 'Meera Admin'],
            ]);

        // A correction replaces the values; the order stays completed from the first save.
        $completedAt = now()->toIso8601String();
        $this->travel(1)->day();
        $this->patchJson(self::ORDERS."/{$order->order_number}/tracking", ['tracking_provider' => 'bluedart', 'tracking_number' => 'BD123'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_at', $completedAt)
            ->assertJsonPath('data.tracking.provider', 'bluedart')
            ->assertJsonPath('data.tracking.provider_name', 'Blue Dart')
            ->assertJsonPath('data.tracking.number', 'BD123');

        // The customer sees it on their next fetch.
        Passport::actingAs($customer, [Role::Customer->scope()]);
        $this->getJson("/api/v1/customer/orders/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.tracking', ['provider' => 'bluedart', 'provider_name' => 'Blue Dart', 'number' => 'BD123']);
    }

    public function test_tracking_needs_both_fields_as_text_and_nothing_else(): void
    {
        $order = $this->order(User::factory()->customerWithProfile()->create());
        $url = self::ORDERS."/{$order->order_number}/tracking";

        $this->patchJson($url, ['tracking_provider' => 'delhivery'])->assertUnprocessable()->assertJsonValidationErrors('tracking_number');
        $this->patchJson($url, ['tracking_number' => '123'])->assertUnprocessable()->assertJsonValidationErrors('tracking_provider');
        // Only couriers from the list; a typed name is not accepted.
        $this->patchJson($url, ['tracking_provider' => 'Delhivery', 'tracking_number' => '123'])->assertUnprocessable()->assertJsonValidationErrors('tracking_provider');
        $this->patchJson($url, ['tracking_provider' => 'other', 'tracking_number' => '123'])->assertUnprocessable()->assertJsonValidationErrors('tracking_provider');
        // A JSON number would lose leading zeros: it must be a string.
        $this->patchJson($url, ['tracking_provider' => 'delhivery', 'tracking_number' => 42981277])->assertUnprocessable()->assertJsonValidationErrors('tracking_number');
        $this->patchJson($url, ['tracking_provider' => 'delhivery', 'tracking_number' => str_repeat('9', 101)])->assertUnprocessable()->assertJsonValidationErrors('tracking_number');
        $this->patchJson($url, ['tracking_provider' => 'delhivery', 'tracking_number' => '123', 'status' => 'completed', 'total_amount' => '1.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Only the tracking provider and tracking number can be changed.', 'total_amount']);

        $order->refresh();
        $this->assertNull($order->tracking_number);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
    }

    public function test_tracking_needs_a_placed_order_that_is_not_cancelled(): void
    {
        $customer = User::factory()->customerWithProfile()->create();
        $body = ['tracking_provider' => 'delhivery', 'tracking_number' => '123'];

        foreach ([
            $this->order($customer, ['status' => OrderStatus::Pending]),
            $this->order($customer, ['status' => OrderStatus::Cancelled, 'placed_at' => null]),
            $this->order($customer, ['status' => OrderStatus::Cancelled]),
        ] as $order) {
            $this->patchJson(self::ORDERS."/{$order->order_number}/tracking", $body)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['order' => 'Tracking can only be added to a placed order that is not cancelled.']);
        }

        $this->patchJson(self::ORDERS.'/ORD-20261003-ZZZZZZ/tracking', $body)->assertNotFound();
    }

    public function test_only_admins_can_see_orders(): void
    {
        $order = $this->order(User::factory()->customerWithProfile()->create());

        Passport::actingAs(User::factory()->customerWithProfile()->create(), [Role::Customer->scope()]);
        $this->getJson(self::ORDERS)->assertForbidden();
        $this->getJson(self::ORDERS."/{$order->order_number}")->assertForbidden();
        $this->patchJson(self::ORDERS."/{$order->order_number}/tracking", ['tracking_provider' => 'dtdc', 'tracking_number' => '1'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson(self::ORDERS)->assertUnauthorized();
    }
}
