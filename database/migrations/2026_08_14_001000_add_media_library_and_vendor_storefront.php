<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines the media-library and vendor-storefront schema migration. */ class extends Migration
{
    /** Applies the media-library and vendor-storefront schema changes. */
    public function up(): void
    {
        Schema::create('media_library_assets', /** Defines the reusable media-library table. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope_key', 80)->index();
            $table->string('disk', 60);
            $table->string('path', 1024);
            $table->string('original_name', 255);
            $table->string('alt_text', 190)->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('visibility', 20)->default('public')->index();
            $table->string('status', 20)->default('active')->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['scope_key', 'sha256'], 'media_library_scope_sha_unique');
            $table->index(['vendor_id', 'status', 'created_at'], 'media_library_vendor_status_created_idx');
            $table->index(['uploaded_by_user_id', 'status'], 'media_library_uploader_status_idx');
        });
    }

    /** Reverts the media-library and vendor-storefront schema changes. */
    public function down(): void
    {
        Schema::dropIfExists('media_library_assets');
    }
};
