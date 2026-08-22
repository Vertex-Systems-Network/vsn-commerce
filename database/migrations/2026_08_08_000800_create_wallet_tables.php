<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('wallets', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('balance_coins')->default(0);
            $table->bigInteger('reserved_coins')->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_transactions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50)->index();
            $table->string('status', 30)->default('posted')->index();
            $table->string('idempotency_key', 190)->unique();
            $table->string('reference_type', 80)->nullable()->index();
            $table->string('reference_id', 190)->nullable()->index();
            $table->foreignId('reversal_of_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('wallet_entries', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('direction', 10);
            $table->unsignedBigInteger('coins');
            $table->bigInteger('balance_after_coins');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['wallet_id', 'id']);
        });

        Schema::create('wallet_holds', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('coins');
            $table->string('status', 30)->default('active')->index();
            $table->string('idempotency_key', 190)->unique();
            $table->string('reference_type', 80)->index();
            $table->string('reference_id', 190)->index();
            $table->foreignId('capture_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['reference_type', 'reference_id', 'status']);
        });

        Schema::create('daily_checkins', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('checkin_date');
            $table->unsignedSmallInteger('streak_day')->default(1);
            $table->unsignedBigInteger('base_reward_coins');
            $table->unsignedBigInteger('bonus_reward_coins')->default(0);
            $table->foreignId('base_transaction_id')->constrained('wallet_transactions')->restrictOnDelete();
            $table->foreignId('bonus_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'checkin_date']);
        });

        Schema::create('coin_purchases', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('coins');
            $table->char('currency', 3)->default('PKR');
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 40)->default('pending')->index();
            $table->string('idempotency_key', 190)->unique();
            $table->foreignId('payment_intent_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('checkout_sessions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('coin_redemption_coins')->default(0)->after('discount_minor');
            $table->foreignId('wallet_hold_id')->nullable()->unique()->after('coin_redemption_coins')->constrained('wallet_holds')->nullOnDelete();
        });

        Schema::table('orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('coin_redemption_coins')->default(0)->after('discount_minor');
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->after('coin_redemption_coins')->constrained('wallet_transactions')->nullOnDelete();
        });

        Schema::table('payment_intents', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->string('purpose', 50)->default('checkout')->after('idempotency_key')->index();
            $table->string('reference_type', 80)->nullable()->after('purpose')->index();
            $table->string('reference_id', 190)->nullable()->after('reference_type')->index();
            $table->foreignId('checkout_session_id')->nullable()->change();
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        // Coin-purchase intents are the only rows allowed to have no checkout session.
        // Remove them before restoring the Milestone-D NOT NULL checkout invariant.
        DB::table('payment_intents')->where('purpose', 'coin_purchase')->delete();
        Schema::table('payment_intents', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->foreignId('checkout_session_id')->nullable(false)->change();
            $table->dropColumn(['purpose', 'reference_type', 'reference_id']);
        });
        Schema::table('orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropConstrainedForeignId('wallet_transaction_id');
            $table->dropColumn('coin_redemption_coins');
        });
        Schema::table('checkout_sessions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropConstrainedForeignId('wallet_hold_id');
            $table->dropColumn('coin_redemption_coins');
        });
        Schema::dropIfExists('coin_purchases');
        Schema::dropIfExists('daily_checkins');
        Schema::dropIfExists('wallet_holds');
        Schema::dropIfExists('wallet_entries');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
