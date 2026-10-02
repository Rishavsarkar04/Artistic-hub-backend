<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            // One profile per user; created after the customer's first sign-in.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->date('date_of_birth')->nullable();
            // App\Enums\Gender.
            $table->string('gender', 20)->nullable();
            // Internal only: never exposed to or editable by the customer.
            $table->text('notes')->nullable();
            $table->timestamps();
            // Soft-deleted together with the user, and restored with them (see User::booted()).
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
