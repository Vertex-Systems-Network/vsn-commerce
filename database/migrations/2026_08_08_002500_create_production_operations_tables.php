<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', /** Inline callback for this operation. */ function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent()->index();
            });
        }

        Schema::create('operational_heartbeats', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('instance_id', 120)->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_runs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('kind', 40)->default('database');
            $table->string('status', 24)->default('running')->index();
            $table->string('storage_disk', 80)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::table('orders', /** Inline callback for this operation. */ function (Blueprint $table): void { $table->index(['payment_status','status','placed_at'],'ops_orders_payment_status_placed_idx'); });
        Schema::table('vendor_orders', /** Inline callback for this operation. */ function (Blueprint $table): void { $table->index(['vendor_id','status','created_at'],'ops_vendor_orders_status_created_idx'); });
        Schema::table('inventory_reservations', /** Inline callback for this operation. */ function (Blueprint $table): void { $table->index(['status','expires_at'],'ops_inventory_reservations_expiry_idx'); });
        Schema::table('notification_deliveries', /** Inline callback for this operation. */ function (Blueprint $table): void { $table->index(['status','available_at'],'ops_notification_delivery_ready_idx'); });
        Schema::table('risk_holds', /** Inline callback for this operation. */ function (Blueprint $table): void { $table->index(['status','expires_at'],'ops_risk_holds_expiry_idx'); });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION vsn_prevent_completed_backup_mutation() RETURNS trigger AS $$
BEGIN
    IF OLD.status = 'completed' AND (
        NEW.storage_path IS DISTINCT FROM OLD.storage_path OR
        NEW.sha256 IS DISTINCT FROM OLD.sha256 OR
        NEW.size_bytes IS DISTINCT FROM OLD.size_bytes
    ) THEN
        RAISE EXCEPTION 'Completed backup artifact metadata is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER backup_runs_completed_artifact_immutable
BEFORE UPDATE ON backup_runs
FOR EACH ROW EXECUTE FUNCTION vsn_prevent_completed_backup_mutation();
SQL);
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS backup_runs_completed_artifact_immutable ON backup_runs; DROP FUNCTION IF EXISTS vsn_prevent_completed_backup_mutation();');
        }
        Schema::table('risk_holds', /** Inline callback for this operation. */ function (Blueprint $table): void { $table->dropIndex('ops_risk_holds_expiry_idx'); });
        Schema::table('notification_deliveries', /** Inline callback for this operation. */ function (Blueprint $table): void { $table->dropIndex('ops_notification_delivery_ready_idx'); });
        Schema::table('inventory_reservations', /** Inline callback for this operation. */ function (Blueprint $table): void { $table->dropIndex('ops_inventory_reservations_expiry_idx'); });
        Schema::table('vendor_orders', /** Inline callback for this operation. */ function (Blueprint $table): void { $table->dropIndex('ops_vendor_orders_status_created_idx'); });
        Schema::table('orders', /** Inline callback for this operation. */ function (Blueprint $table): void { $table->dropIndex('ops_orders_payment_status_placed_idx'); });
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('operational_heartbeats');
        // failed_jobs may predate this milestone in an installation; do not destroy it on rollback.
    }
};
