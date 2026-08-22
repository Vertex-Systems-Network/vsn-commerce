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
        Schema::table('production_acceptance_runs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->foreignId('deployment_run_id')->nullable()->after('actor_user_id')->constrained('deployment_runs')->nullOnDelete();
            $table->char('artifact_sha256', 64)->nullable()->after('environment');
            $table->char('verification_sha256', 64)->nullable()->after('artifact_sha256');
            $table->char('evidence_sha256', 64)->nullable()->after('verification_sha256');
            $table->index(['release', 'environment', 'status'], 'acceptance_release_env_status_idx');
        });

        Schema::create('release_candidate_manifests', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('acceptance_run_id')->unique()->constrained('production_acceptance_runs')->restrictOnDelete();
            $table->foreignId('deployment_run_id')->nullable()->constrained('deployment_runs')->nullOnDelete();
            $table->foreignId('sealed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release', 80)->index();
            $table->string('environment', 40)->index();
            $table->char('artifact_sha256', 64);
            $table->char('verification_sha256', 64);
            $table->char('acceptance_evidence_sha256', 64);
            $table->char('manifest_sha256', 64)->unique();
            $table->jsonb('evidence');
            $table->timestamp('sealed_at')->index();
            $table->timestamps();
            $table->index(['release', 'environment', 'sealed_at'], 'rc_release_env_sealed_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE OR REPLACE FUNCTION vsn_block_rc_manifest_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'release candidate manifests are immutable'; END; $$ LANGUAGE plpgsql");
            DB::statement('CREATE TRIGGER rc_manifest_immutable BEFORE UPDATE OR DELETE ON release_candidate_manifests FOR EACH ROW EXECUTE FUNCTION vsn_block_rc_manifest_mutation()');
        } elseif (in_array(DB::getDriverName(), ['mysql','mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER rc_manifest_immutable_upd BEFORE UPDATE ON release_candidate_manifests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release candidate manifests are immutable'");
            DB::unprepared("CREATE TRIGGER rc_manifest_immutable_del BEFORE DELETE ON release_candidate_manifests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'release candidate manifests are immutable'");
            DB::unprepared("CREATE TRIGGER acceptance_signoff_immutable_upd BEFORE UPDATE ON production_acceptance_signoffs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'production acceptance signoffs are immutable'");
            DB::unprepared("CREATE TRIGGER acceptance_signoff_immutable_del BEFORE DELETE ON production_acceptance_signoffs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'production acceptance signoffs are immutable'");
            DB::unprepared("CREATE TRIGGER dr_evidence_immutable_upd BEFORE UPDATE ON disaster_recovery_drills FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disaster recovery evidence is immutable'");
            DB::unprepared("CREATE TRIGGER dr_evidence_immutable_del BEFORE DELETE ON disaster_recovery_drills FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disaster recovery evidence is immutable'");
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS rc_manifest_immutable ON release_candidate_manifests');
            DB::statement('DROP FUNCTION IF EXISTS vsn_block_rc_manifest_mutation()');
        } elseif (in_array(DB::getDriverName(), ['mysql','mariadb'], true)) {
            foreach (['rc_manifest_immutable_upd','rc_manifest_immutable_del','acceptance_signoff_immutable_upd','acceptance_signoff_immutable_del','dr_evidence_immutable_upd','dr_evidence_immutable_del'] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
        Schema::dropIfExists('release_candidate_manifests');
        Schema::table('production_acceptance_runs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropIndex('acceptance_release_env_status_idx');
            $table->dropConstrainedForeignId('deployment_run_id');
            $table->dropColumn(['artifact_sha256','verification_sha256','evidence_sha256']);
        });
    }
};
