<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::table('payment_intents', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedSmallInteger('initialization_attempts')->default(0);
            $table->timestamp('last_initialization_attempt_at')->nullable();
            $table->string('provider_status', 80)->nullable()->index('pay_int_provider_status_idx');
            $table->timestamp('provider_synced_at')->nullable()->index('pay_int_provider_sync_idx');
            $table->text('provider_sync_error')->nullable();
        });
        Schema::table('payment_webhook_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->timestamp('last_duplicate_at')->nullable();
        });
        Schema::table('shipments', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedSmallInteger('creation_attempts')->default(0);
            $table->timestamp('last_creation_attempt_at')->nullable();
            $table->string('provider_status', 80)->nullable()->index('ship_provider_status_idx');
            $table->timestamp('provider_synced_at')->nullable()->index('ship_provider_sync_idx');
            $table->text('provider_sync_error')->nullable();
        });
        Schema::table('shipping_webhook_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->timestamp('last_duplicate_at')->nullable();
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::table('shipping_webhook_events', /** Inline callback for this operation. */ fn (Blueprint $t) => $t->dropColumn(['duplicate_count','last_duplicate_at']));
        Schema::table('shipments', /** Inline callback for this operation. */ function (Blueprint $t): void {
            $t->dropIndex('ship_provider_status_idx'); $t->dropIndex('ship_provider_sync_idx');
            $t->dropColumn(['creation_attempts','last_creation_attempt_at','provider_status','provider_synced_at','provider_sync_error']);
        });
        Schema::table('payment_webhook_events', /** Inline callback for this operation. */ fn (Blueprint $t) => $t->dropColumn(['duplicate_count','last_duplicate_at']));
        Schema::table('payment_intents', /** Inline callback for this operation. */ function (Blueprint $t): void {
            $t->dropIndex('pay_int_provider_status_idx'); $t->dropIndex('pay_int_provider_sync_idx');
            $t->dropColumn(['initialization_attempts','last_initialization_attempt_at','provider_status','provider_synced_at','provider_sync_error']);
        });
    }
};
