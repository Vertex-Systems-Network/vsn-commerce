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
        Schema::create('production_acceptance_runs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release', 80)->nullable();
            $table->string('environment', 40);
            $table->string('status', 32)->index();
            $table->unsignedInteger('blockers_count')->default(0);
            $table->unsignedInteger('warnings_count')->default(0);
            $table->jsonb('checks');
            $table->timestamp('evaluated_at')->index();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('production_acceptance_signoffs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('acceptance_run_id')->constrained('production_acceptance_runs')->cascadeOnDelete();
            $table->string('area', 40);
            $table->foreignId('signed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->text('comment')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();
            $table->unique(['acceptance_run_id', 'area']);
        });

        Schema::create('disaster_recovery_drills', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('backup_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->string('status', 16)->index();
            $table->unsignedInteger('rto_minutes')->nullable();
            $table->unsignedInteger('rpo_minutes')->nullable();
            $table->string('backup_sha256', 64)->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->index();
            $table->timestamps();
        });

        Schema::create('incident_records', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('severity', 16)->index();
            $table->string('type', 40)->index();
            $table->string('status', 24)->index();
            $table->string('title', 180);
            $table->text('summary')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
            $table->index(['severity', 'status']);
        });


        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE OR REPLACE FUNCTION vsn_block_acceptance_evidence_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'acceptance/dr evidence is append-only'; END; $$ LANGUAGE plpgsql");
            foreach (['production_acceptance_signoffs','disaster_recovery_drills'] as $table) {
                DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION vsn_block_acceptance_evidence_mutation()");
            }
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') DB::statement('DROP FUNCTION IF EXISTS vsn_block_acceptance_evidence_mutation() CASCADE');
        Schema::dropIfExists('incident_records');
        Schema::dropIfExists('disaster_recovery_drills');
        Schema::dropIfExists('production_acceptance_signoffs');
        Schema::dropIfExists('production_acceptance_runs');
    }
};
