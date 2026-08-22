<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration {
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::table('kyc_verifications', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedSmallInteger('provider_attempts')->default(0)->after('provider_reference');
            $table->timestamp('provider_last_attempt_at')->nullable()->after('provider_attempts');
            $table->timestamp('provider_last_sync_at')->nullable()->after('provider_last_attempt_at');
            $table->timestamp('next_provider_retry_at')->nullable()->after('provider_last_sync_at');
            $table->text('provider_last_error')->nullable()->after('next_provider_retry_at');
            $table->index(['status','next_provider_retry_at'], 'kyc_retry_due_idx');
        });

        Schema::create('notification_delivery_attempts', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 30)->index();
            $table->string('provider', 50)->nullable();
            $table->string('provider_reference', 190)->nullable();
            $table->text('error')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unique(['notification_delivery_id','attempt_number'], 'notif_delivery_attempt_uq');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION vsn_block_notification_attempt_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'notification delivery attempts are immutable'; END; $$ LANGUAGE plpgsql;");
            DB::unprepared('CREATE TRIGGER notification_delivery_attempts_immutable BEFORE UPDATE OR DELETE ON notification_delivery_attempts FOR EACH ROW EXECUTE FUNCTION vsn_block_notification_attempt_mutation();');
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') DB::unprepared('DROP FUNCTION IF EXISTS vsn_block_notification_attempt_mutation() CASCADE;');
        Schema::dropIfExists('notification_delivery_attempts');
        Schema::table('kyc_verifications', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropIndex('kyc_retry_due_idx');
            $table->dropColumn(['provider_attempts','provider_last_attempt_at','provider_last_sync_at','next_provider_retry_at','provider_last_error']);
        });
    }
};
