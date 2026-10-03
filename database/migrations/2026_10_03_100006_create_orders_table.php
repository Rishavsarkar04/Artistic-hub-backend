<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Orders are history: a profile with orders cannot be force-deleted.
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            // Client-facing id, e.g. AH-20261003-7K2Q9M.
            $table->string('order_number', 30)->unique();
            // App\Enums\OrderStatus. pending until the payment is confirmed.
            $table->string('status', 20)->index();

            // Rupees. total = subtotal - discount + shipping. Prices include GST; shipping is not
            // charged for now; discount is an order-level discount (none yet), not the MRP savings.
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('shipping_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            // ISO 4217 code all of the order's amounts (and its items') are in; copied from the shop
            // currency (config app.currency) at checkout.
            $table->string('currency', 3);

            // Snapshots, copied at checkout: later profile or address edits never change the order.
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 20);
            $table->string('recipient_name');
            $table->string('recipient_phone', 20);
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('postal_code', 20);
            $table->string('country', 100);

            // App\Enums\TrackingProvider (a fixed list of couriers).
            $table->string('tracking_provider', 30)->nullable();
            // Text, so leading zeros survive. Set together with the provider by an admin (BE-ORDER-03).
            $table->string('tracking_number', 100)->nullable();
            // Who saved the current tracking values and when (audit; earlier values are not kept).
            $table->timestamp('tracking_updated_at')->nullable();
            $table->foreignId('tracking_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('cancellation_reason')->nullable();
            // Why an admin should look at this order, e.g. it was paid when stock was short (no
            // reservation). Null when there is nothing to check. Never shown to the customer.
            $table->text('review_reason')->nullable();

            // Set when the payment is confirmed (status becomes confirmed): a pending checkout is not a
            // placed order, so created_at is when Pay was pressed and placed_at is the order date shown.
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['customer_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
