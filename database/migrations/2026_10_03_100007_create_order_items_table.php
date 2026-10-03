<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Becomes null if the variant is deleted; the snapshot below keeps the history.
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            // Snapshots, copied at checkout.
            $table->string('product_name');
            $table->string('variant_name');
            $table->string('sku', 64);
            $table->string('variant_photo_path')->nullable();
            $table->decimal('original_unit_price', 10, 2);
            $table->decimal('selling_unit_price', 10, 2);

            $table->unsignedInteger('quantity');
            // selling_unit_price × quantity
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            // subtotal - discount_amount
            $table->decimal('total_amount', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
