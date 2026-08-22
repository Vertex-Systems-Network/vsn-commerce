<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('wallet_coin_lots', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('origin_lot_id')->nullable()->constrained('wallet_coin_lots')->nullOnDelete();
            $table->string('source_type', 50)->index();
            $table->unsignedBigInteger('original_coins');
            $table->unsignedBigInteger('remaining_coins');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('expired_at')->nullable();
            $table->foreignId('expiration_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['wallet_id', 'remaining_coins', 'expires_at'], 'wallet_coin_lot_spend_idx');
            $table->index(['user_id', 'expires_at'], 'wallet_coin_lot_user_exp_idx');
        });

        Schema::create('wallet_coin_consumptions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('debit_transaction_id')->constrained('wallet_transactions')->cascadeOnDelete();
            $table->foreignId('wallet_coin_lot_id')->constrained('wallet_coin_lots')->restrictOnDelete();
            $table->unsignedBigInteger('coins');
            $table->unsignedBigInteger('restored_coins')->default(0);
            $table->timestamps();
            $table->unique(['debit_transaction_id', 'wallet_coin_lot_id'], 'wallet_coin_consume_uq');
        });

        Schema::create('affiliate_account_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40)->index();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['affiliate_account_id', 'occurred_at'], 'aff_account_event_idx');
        });

        Schema::table('games', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedInteger('max_entries_per_user')->nullable()->after('max_entries');
            $table->unsignedBigInteger('winner_bonus_coins')->default(0)->after('entry_coins');
        });

        Schema::table('game_prize_fulfillments', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->after('fulfilled_by_user_id')->constrained('wallet_transactions')->nullOnDelete();
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::table('game_prize_fulfillments', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropConstrainedForeignId('wallet_transaction_id');
        });
        Schema::table('games', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropColumn(['max_entries_per_user', 'winner_bonus_coins']);
        });
        Schema::dropIfExists('affiliate_account_events');
        Schema::dropIfExists('wallet_coin_consumptions');
        Schema::dropIfExists('wallet_coin_lots');
    }
};
