<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('reference_id')->unique();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            // Deleting a variant removes it from every cart.
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            // At least 1 (checked by the app). No prices: every cart read shows current prices.
            $table->unsignedInteger('quantity');
            $table->timestamps();

            // A variant appears once per cart; adding it again increases the quantity.
            $table->unique(['cart_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
