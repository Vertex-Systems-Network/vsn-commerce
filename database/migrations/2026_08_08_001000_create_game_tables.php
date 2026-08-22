<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('games', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('draft')->index();
            $table->unsignedBigInteger('entry_coins');
            $table->unsignedBigInteger('max_entries')->nullable();
            $table->unsignedBigInteger('total_entries')->default(0);
            $table->timestamp('opens_at')->index();
            $table->timestamp('closes_at')->index();
            $table->timestamp('announcement_at')->index();
            $table->string('rules_version', 60);
            $table->char('commitment_hash', 64)->unique();
            $table->text('draw_secret_ciphertext');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('drawn_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['status', 'opens_at']);
            $table->index(['status', 'closes_at']);
        });

        Schema::create('game_entries', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('coins_spent');
            $table->string('idempotency_key', 190)->unique();
            $table->foreignId('wallet_transaction_id')->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->string('rules_version', 60);
            $table->timestamp('consented_at');
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['game_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('game_entry_refunds', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_entry_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->string('reason', 120);
            $table->timestamp('refunded_at');
            $table->timestamps();
        });

        Schema::create('game_draws', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('game_id')->unique()->constrained()->restrictOnDelete();
            $table->char('commitment_hash', 64);
            $table->char('snapshot_hash', 64);
            $table->jsonb('snapshot');
            $table->longText('snapshot_canonical');
            $table->text('revealed_secret');
            $table->char('selection_hash', 64);
            $table->unsignedBigInteger('total_tickets');
            $table->unsignedBigInteger('winning_ticket_number');
            $table->foreignId('winner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('winner_entry_id')->constrained('game_entries')->restrictOnDelete();
            $table->timestamp('drawn_at');
            $table->timestamps();
        });

        Schema::create('game_prize_fulfillments', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('winner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('fulfilled_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('method', 50)->default('manual_handoff');
            $table->string('reference', 190)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('fulfilled_at');
            $table->timestamps();
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('game_prize_fulfillments');
        Schema::dropIfExists('game_draws');
        Schema::dropIfExists('game_entry_refunds');
        Schema::dropIfExists('game_entries');
        Schema::dropIfExists('games');
    }
};
