<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::table('orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->timestamp('affiliate_accrued_at')->nullable()->index();
        });

        Schema::create('affiliate_accounts', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('referral_code', 24)->unique();
            $table->string('status', 24)->default('active')->index();
            $table->string('terms_version', 40);
            $table->timestamp('terms_accepted_at');
            $table->timestamp('suspended_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_relationships', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referral_account_id')->constrained('affiliate_accounts')->restrictOnDelete();
            $table->timestamp('joined_at');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['parent_user_id', 'user_id']);
        });

        Schema::create('affiliate_commissions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('beneficiary_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('level_no');
            $table->unsignedInteger('rate_bps');
            $table->char('currency', 3);
            $table->unsignedBigInteger('eligible_spend_minor');
            $table->unsignedBigInteger('reward_coins');
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('available_at')->index();
            $table->timestamp('credited_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('reversal_wallet_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'level_no']);
            $table->index(['beneficiary_id', 'status', 'available_at']);
            $table->index(['buyer_id', 'order_id']);
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliate_relationships');
        Schema::dropIfExists('affiliate_accounts');
        Schema::table('orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropColumn('affiliate_accrued_at');
        });
    }
};
