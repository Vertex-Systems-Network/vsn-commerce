<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('gift_sender_profiles', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('lifetime_gift_coins')->default(0); $table->string('current_level',40)->default('starter'); $table->timestamps();
        });
        Schema::create('gift_sender_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id(); $table->ulid('public_id')->unique(); $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('event_type',40)->index(); $table->unsignedBigInteger('gift_coins'); $table->string('idempotency_key',190)->unique();
            $table->string('reference_type',80)->index(); $table->string('reference_id',190)->index(); $table->jsonb('metadata')->nullable(); $table->timestamp('occurred_at'); $table->timestamps();
            $table->index(['user_id','occurred_at']);
        });
        Schema::create('gift_sender_rewards', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id(); $table->ulid('public_id')->unique(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reward_code',80); $table->string('level',40); $table->string('status',30)->default('available')->index();
            $table->foreignId('source_event_id')->nullable()->constrained('gift_sender_events')->nullOnDelete(); $table->jsonb('metadata')->nullable();
            $table->timestamp('awarded_at'); $table->timestamp('consumed_at')->nullable(); $table->timestamps(); $table->unique(['user_id','reward_code']);
        });
        Schema::create('gifts', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id(); $table->ulid('public_id')->unique(); $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete(); $table->foreignId('checkout_session_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete(); $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete(); $table->string('status',40)->default('awaiting_payment')->index();
            $table->char('currency',3); $table->unsignedBigInteger('product_value_minor'); $table->unsignedBigInteger('gift_wrap_minor')->default(0);
            $table->unsignedBigInteger('gift_value_minor'); $table->unsignedBigInteger('gift_value_coins'); $table->text('message')->nullable(); $table->boolean('anonymous')->default(false);
            $table->boolean('gift_wrap')->default(false); $table->timestamp('scheduled_for')->nullable()->index(); $table->timestamp('paid_at')->nullable();
            $table->timestamp('progress_recorded_at')->nullable(); $table->timestamp('recipient_notified_at')->nullable(); $table->string('idempotency_key',120)->unique();
            $table->jsonb('metadata')->nullable(); $table->timestamps(); $table->index(['sender_user_id','created_at']); $table->index(['recipient_user_id','created_at']);
        });
        Schema::create('gift_notifications', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id(); $table->ulid('public_id')->unique(); $table->foreignId('gift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete(); $table->string('type',50); $table->string('status',30)->default('pending')->index();
            $table->timestamp('available_at')->index(); $table->timestamp('delivered_at')->nullable(); $table->jsonb('payload')->nullable(); $table->timestamps();
            $table->unique(['gift_id','type']); $table->index(['recipient_user_id','status','available_at']);
        });
    }
    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('gift_notifications'); Schema::dropIfExists('gifts'); Schema::dropIfExists('gift_sender_rewards');
        Schema::dropIfExists('gift_sender_events'); Schema::dropIfExists('gift_sender_profiles');
    }
};
