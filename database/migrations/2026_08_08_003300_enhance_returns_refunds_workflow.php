<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::table('return_requests', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->string('return_carrier', 120)->nullable()->after('return_tracking_reference');
            $table->timestamp('shipped_at')->nullable()->index()->after('return_carrier');
            $table->timestamp('inspection_completed_at')->nullable()->after('received_at');
            $table->timestamp('cancelled_at')->nullable()->after('resolved_at');
        });

        Schema::table('return_request_items', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedSmallInteger('approved_quantity')->default(0)->after('quantity');
            $table->unsignedSmallInteger('received_quantity')->default(0)->after('approved_quantity');
            $table->unsignedSmallInteger('accepted_quantity')->default(0)->after('received_quantity');
            $table->string('condition', 32)->nullable()->after('restock');
            $table->text('inspection_note')->nullable()->after('condition');
        });

        Schema::table('refunds', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedSmallInteger('attempt_count')->default(0)->after('idempotency_key');
            $table->timestamp('last_attempt_at')->nullable()->after('attempt_count');
            $table->string('manual_reference', 190)->nullable()->after('wallet_refund_transaction_id');
        });

        Schema::create('refund_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 60)->index();
            $table->string('reference', 190)->nullable();
            $table->text('message')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['refund_id', 'occurred_at'], 'refund_events_timeline_idx');
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('refund_events');
        Schema::table('refunds', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropColumn(['attempt_count','last_attempt_at','manual_reference']));
        Schema::table('return_request_items', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropColumn(['approved_quantity','received_quantity','accepted_quantity','condition','inspection_note']));
        Schema::table('return_requests', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropColumn(['return_carrier','shipped_at','inspection_completed_at','cancelled_at']));
    }
};
