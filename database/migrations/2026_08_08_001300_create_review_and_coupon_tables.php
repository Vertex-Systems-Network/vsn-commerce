<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('reviews', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            $table->boolean('verified_purchase')->default(true);
            $table->timestamp('submitted_at');
            $table->timestamp('moderated_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('moderation_note')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'submitted_at']);
            $table->index(['product_id', 'status', 'submitted_at']);
        });

        Schema::create('review_images', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40)->default('public');
            $table->string('path', 500);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['review_id', 'sort_order']);
        });

        Schema::create('review_reward_coupons', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 32)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('review_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('percent_bps')->default(1000);
            $table->string('status', 30)->default('available')->index();
            $table->foreignId('reserved_checkout_session_id')->nullable()->unique()->constrained('checkout_sessions')->nullOnDelete();
            $table->foreignId('redeemed_order_id')->nullable()->unique()->constrained('orders')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('review_reminders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('scheduled')->index();
            $table->timestamp('scheduled_for')->index();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('review_reminders');
        Schema::dropIfExists('review_reward_coupons');
        Schema::dropIfExists('review_images');
        Schema::dropIfExists('reviews');
    }
};
