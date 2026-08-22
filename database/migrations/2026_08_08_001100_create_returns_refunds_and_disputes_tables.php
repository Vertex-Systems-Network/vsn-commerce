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
            $table->unsignedBigInteger('refunded_minor')->default(0)->after('total_minor');
            $table->unsignedBigInteger('cash_refunded_minor')->default(0)->after('refunded_minor');
            $table->unsignedBigInteger('coin_refunded_coins')->default(0)->after('cash_refunded_minor');
            $table->timestamp('delivered_at')->nullable()->index()->after('placed_at');
        });

        Schema::table('vendor_orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('refunded_minor')->default(0)->after('seller_payable_minor');
            $table->unsignedBigInteger('platform_commission_reversed_minor')->default(0)->after('refunded_minor');
            $table->unsignedBigInteger('seller_payable_reversed_minor')->default(0)->after('platform_commission_reversed_minor');
        });

        Schema::table('order_items', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedSmallInteger('returned_quantity')->default(0)->after('quantity');
            $table->unsignedSmallInteger('refunded_quantity')->default(0)->after('returned_quantity');
        });

        Schema::create('return_requests', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('submitted')->index();
            $table->string('resolution', 32)->index();
            $table->string('reason', 120);
            $table->text('details')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('requested_minor')->default(0);
            $table->unsignedBigInteger('approved_minor')->default(0);
            $table->string('return_tracking_reference', 190)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'submitted_at']);
            $table->index(['order_id', 'status']);
        });

        Schema::create('return_request_items', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('requested_minor');
            $table->unsignedBigInteger('approved_minor')->default(0);
            $table->boolean('restock')->default(true);
            $table->timestamp('restocked_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['return_request_id', 'order_item_id']);
        });

        Schema::create('refunds', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('return_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->string('resolution', 32);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('cash_refund_minor')->default(0);
            $table->unsignedBigInteger('coin_refund_minor')->default(0);
            $table->unsignedBigInteger('coin_refund_coins')->default(0);
            $table->string('idempotency_key', 190)->unique();
            $table->foreignId('payment_refund_transaction_id')->nullable()->unique()->constrained('payment_transactions')->nullOnDelete();
            $table->foreignId('wallet_refund_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('vendor_refund_adjustments', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('refund_minor');
            $table->unsignedBigInteger('platform_commission_reversal_minor')->default(0);
            $table->unsignedBigInteger('seller_payable_reversal_minor')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['refund_id', 'vendor_order_id']);
        });

        Schema::create('affiliate_commission_refunds', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_commission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('refunded_eligible_minor');
            $table->unsignedBigInteger('reversed_coins')->default(0);
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['affiliate_commission_id', 'refund_id'], 'aff_comm_refund_uq');
        });

        Schema::create('disputes', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('return_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('open')->index();
            $table->string('outcome', 40)->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('dispute_messages', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message');
            $table->jsonb('attachments')->nullable();
            $table->timestamps();
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('dispute_messages');
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('affiliate_commission_refunds');
        Schema::dropIfExists('vendor_refund_adjustments');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('return_request_items');
        Schema::dropIfExists('return_requests');

        Schema::table('order_items', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropColumn(['returned_quantity', 'refunded_quantity']);
        });
        Schema::table('vendor_orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropColumn(['refunded_minor', 'platform_commission_reversed_minor', 'seller_payable_reversed_minor']);
        });
        Schema::table('orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropColumn(['refunded_minor', 'cash_refunded_minor', 'coin_refunded_coins', 'delivered_at']);
        });
    }
};
