<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Client-facing id; also sent to Razorpay as the payment link's reference_id.
            $table->string('payment_number', 40)->unique();
            // App\Enums\PaymentMethod. Unknown until the customer pays.
            $table->string('method', 20)->nullable();
            $table->string('provider', 20);
            // App\Enums\PaymentStatus.
            $table->string('status', 20)->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            // Razorpay payment id, once paid.
            $table->string('transaction_id')->nullable()->unique();
            // Razorpay payment link id.
            $table->string('payment_session_id')->nullable()->unique();
            // Hosted payment page the customer is sent to.
            $table->string('payment_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
