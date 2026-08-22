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
        Schema::create('deployment_runs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('backup_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->string('environment', 40)->index();
            $table->string('release', 80)->index();
            $table->string('previous_release', 80)->nullable();
            $table->char('commit_sha', 40)->nullable();
            $table->char('artifact_sha256', 64)->nullable();
            $table->string('status', 24)->index();
            $table->string('phase', 40)->index();
            $table->unsignedInteger('migration_batch_before')->nullable();
            $table->unsignedInteger('migration_batch_after')->nullable();
            $table->boolean('maintenance_used')->default(false);
            $table->jsonb('evidence')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
            $table->index(['environment', 'status', 'started_at'], 'deploy_env_status_started_idx');
        });

        Schema::create('incident_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('incident_record_id')->constrained('incident_records')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 32)->index();
            $table->text('message');
            $table->jsonb('evidence')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['incident_record_id', 'occurred_at'], 'incident_event_timeline_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE OR REPLACE FUNCTION vsn_block_incident_event_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'incident events are append-only'; END; $$ LANGUAGE plpgsql");
            DB::statement('CREATE TRIGGER incident_events_immutable BEFORE UPDATE OR DELETE ON incident_events FOR EACH ROW EXECUTE FUNCTION vsn_block_incident_event_mutation()');
        } elseif (in_array(DB::getDriverName(), ['mysql','mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER incident_events_immutable_update BEFORE UPDATE ON incident_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'incident events are append-only'");
            DB::unprepared("CREATE TRIGGER incident_events_immutable_delete BEFORE DELETE ON incident_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'incident events are append-only'");
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS incident_events_immutable ON incident_events');
            DB::statement('DROP FUNCTION IF EXISTS vsn_block_incident_event_mutation()');
        } elseif (in_array(DB::getDriverName(), ['mysql','mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS incident_events_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS incident_events_immutable_delete');
        }
        Schema::dropIfExists('incident_events');
        Schema::dropIfExists('deployment_runs');
    }
};
