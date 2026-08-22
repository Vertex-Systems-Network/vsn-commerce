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
        Schema::create('report_schedules', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('report_type', 60)->index();
            $table->string('cadence', 20)->index();
            $table->string('timezone', 64)->default('UTC');
            $table->string('run_at_local', 5)->default('08:00');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->jsonb('filters')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'next_run_at']);
        });

        Schema::create('report_exports', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('report_schedule_id')->nullable()->constrained('report_schedules')->nullOnDelete();
            $table->string('report_type', 60)->index();
            $table->string('format', 12)->default('csv');
            $table->string('status', 24)->default('queued')->index();
            $table->jsonb('filters')->nullable();
            $table->string('storage_disk', 80)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('rows_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['requested_by_user_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION vsn_prevent_ready_report_export_mutation() RETURNS trigger AS $$
BEGIN
    IF OLD.status = 'ready' AND (NEW.storage_path IS DISTINCT FROM OLD.storage_path OR NEW.sha256 IS DISTINCT FROM OLD.sha256 OR NEW.rows_count IS DISTINCT FROM OLD.rows_count) THEN
        RAISE EXCEPTION 'Ready report export file metadata is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER report_exports_ready_file_immutable BEFORE UPDATE ON report_exports FOR EACH ROW EXECUTE FUNCTION vsn_prevent_ready_report_export_mutation();
SQL);
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS report_exports_ready_file_immutable ON report_exports; DROP FUNCTION IF EXISTS vsn_prevent_ready_report_export_mutation();');
        }
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('report_schedules');
    }
};
