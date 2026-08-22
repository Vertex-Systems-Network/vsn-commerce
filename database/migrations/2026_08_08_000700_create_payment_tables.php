<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('payment_intents', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('checkout_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 120)->unique();
            $table->string('provider', 60)->index();
            $table->string('payment_method', 60);
            $table->string('status', 40)->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('provider_payment_id', 190)->nullable();
            $table->jsonb('client_action')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_payment_id']);
            $table->index(['checkout_session_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('payment_transactions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('payment_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 60)->index();
            $table->string('type', 40)->index();
            $table->string('status', 30)->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('provider_transaction_id', 190)->nullable();
            $table->string('idempotency_key', 190)->unique();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['payment_intent_id', 'occurred_at']);
        });

        Schema::create('payment_webhook_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 60);
            $table->string('provider_event_id', 190);
            $table->string('event_type', 100)->index();
            $table->string('status', 30)->default('received')->index();
            $table->string('payload_sha256', 64);
            $table->jsonb('payload');
            $table->text('processing_error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_intents');
    }
};
