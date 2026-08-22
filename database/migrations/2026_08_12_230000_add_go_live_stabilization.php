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
        Schema::create('go_live_windows', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('release_candidate_manifest_id')->constrained('release_candidate_manifests')->restrictOnDelete();
            $table->foreignId('production_acceptance_run_id')->constrained('production_acceptance_runs')->restrictOnDelete();
            $table->foreignId('deployment_run_id')->nullable()->constrained('deployment_runs')->nullOnDelete();
            $table->foreignId('incident_record_id')->nullable()->constrained('incident_records')->nullOnDelete();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release', 80)->index();
            $table->string('environment', 40)->index();
            $table->string('active_environment', 40)->nullable()->unique('go_live_one_active_env_uq');
            $table->string('status', 24)->index();
            $table->char('artifact_sha256', 64);
            $table->char('composer_lock_sha256', 64)->nullable();
            $table->char('npm_lock_sha256', 64)->nullable();
            $table->char('verification_sha256', 64);
            $table->char('release_manifest_sha256', 64);
            $table->unsignedInteger('observation_interval_minutes')->default(5);
            $table->unsignedInteger('required_healthy_observations')->default(6);
            $table->json('thresholds')->nullable();
            $table->json('baseline')->nullable();
            $table->text('close_note')->nullable();
            $table->timestamp('opened_at')->index();
            $table->timestamp('rollback_expires_at')->nullable()->index();
            $table->timestamp('stabilization_due_at')->nullable()->index();
            $table->timestamp('stable_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamps();
            $table->index(['environment','status','opened_at'], 'go_live_env_status_open_idx');
        });

        Schema::create('go_live_observations', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('go_live_window_id')->constrained('go_live_windows')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('status', 20)->index();
            $table->unsignedInteger('blocker_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->json('snapshot');
            $table->json('blockers')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamp('observed_at')->index();
            $table->timestamps();
            $table->unique(['go_live_window_id','sequence'], 'go_live_window_sequence_uq');
            $table->index(['go_live_window_id','status','observed_at'], 'go_live_obs_status_time_idx');
        });

        Schema::create('go_live_stabilization_signoffs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('go_live_window_id')->constrained('go_live_windows')->restrictOnDelete();
            $table->string('area', 32);
            $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 16);
            $table->text('comment')->nullable();
            $table->json('evidence');
            $table->timestamp('signed_at')->index();
            $table->unique(['go_live_window_id','area'], 'go_live_signoff_area_uq');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE OR REPLACE FUNCTION vsn_block_go_live_evidence_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'go-live evidence is append-only'; END; $$ LANGUAGE plpgsql");
            DB::statement('CREATE TRIGGER go_live_observations_immutable BEFORE UPDATE OR DELETE ON go_live_observations FOR EACH ROW EXECUTE FUNCTION vsn_block_go_live_evidence_mutation()');
            DB::statement('CREATE TRIGGER go_live_signoffs_immutable BEFORE UPDATE OR DELETE ON go_live_stabilization_signoffs FOR EACH ROW EXECUTE FUNCTION vsn_block_go_live_evidence_mutation()');
        }
        if (in_array(DB::getDriverName(), ['mysql','mariadb'], true)) {
            foreach (['go_live_observations','go_live_stabilization_signoffs'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='go-live evidence is append-only'");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='go-live evidence is append-only'");
            }
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS go_live_observations_immutable ON go_live_observations');
            DB::statement('DROP TRIGGER IF EXISTS go_live_signoffs_immutable ON go_live_stabilization_signoffs');
            DB::statement('DROP FUNCTION IF EXISTS vsn_block_go_live_evidence_mutation()');
        }
        if (in_array(DB::getDriverName(), ['mysql','mariadb'], true)) {
            foreach (['go_live_observations','go_live_stabilization_signoffs'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            }
        }
        Schema::dropIfExists('go_live_stabilization_signoffs');
        Schema::dropIfExists('go_live_observations');
        Schema::dropIfExists('go_live_windows');
    }
};
