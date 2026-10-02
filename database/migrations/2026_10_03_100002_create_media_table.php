<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            // App\Enums\MediaCollection: what the file is for (variant_photo, avatar).
            $table->string('collection', 30);
            // Disk the file was stored on, so files keep resolving if MEDIA_DISK changes later.
            $table->string('disk', 30);
            $table->string('path')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // The owner (a product variant, a customer profile). Null until the upload is attached;
            // unattached media older than 24 hours is pruned with its file.
            $table->nullableMorphs('mediable');
            // Display order within the owner's collection; 0 is the cover.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['collection', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
