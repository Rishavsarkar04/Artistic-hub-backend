<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->ulid('reference_id')->unique();
            // Unique; MySQL's default collation compares it case-insensitively.
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            // Turning a product off turns all its variants off (ProductService).
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
