<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->ulid('reference_id')->unique();
            // Deleting a product deletes its variants. Order items keep their own snapshot.
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 64)->unique();
            $table->string('slug')->unique();
            // Rupees with paise. selling_price <= original_price; a lower selling price is a discount.
            $table->decimal('original_price', 10, 2);
            $table->decimal('selling_price', 10, 2);
            $table->unsignedInteger('stock')->default(0);
            // Falls back to the product's description when null.
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
