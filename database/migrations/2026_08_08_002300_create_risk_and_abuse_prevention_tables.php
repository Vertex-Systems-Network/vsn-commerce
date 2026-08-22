<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration {
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('risk_profiles', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('score')->default(0)->index();
            $table->string('level', 20)->default('low')->index();
            $table->string('status', 30)->default('monitored')->index();
            $table->jsonb('signal_summary')->nullable();
            $table->timestamp('last_evaluated_at')->nullable()->index();
            $table->timestamps();
            $table->index(['level','last_evaluated_at']);
        });

        Schema::create('risk_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 100)->index();
            $table->string('scope', 40)->nullable()->index();
            $table->string('severity', 20)->default('low')->index();
            $table->smallInteger('score_delta')->default(0);
            $table->string('source_type', 80)->nullable()->index();
            $table->string('source_id', 190)->nullable()->index();
            $table->string('idempotency_key', 190)->nullable()->unique();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();
            $table->index(['user_id','occurred_at']);
            $table->index(['vendor_id','occurred_at']);
        });

        Schema::create('risk_cases', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('open')->index();
            $table->string('priority', 20)->default('medium')->index();
            $table->string('title', 190);
            $table->text('summary')->nullable();
            $table->unsignedSmallInteger('score_at_open')->default(0);
            $table->text('resolution')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('opened_at')->useCurrent()->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status','priority','opened_at']);
        });

        Schema::create('risk_holds', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('risk_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 40)->index();
            $table->string('status', 20)->default('active')->index();
            $table->text('reason');
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('released_at')->nullable();
            $table->text('release_note')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id','scope','status']);
            $table->index(['vendor_id','scope','status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION vsn_prevent_risk_event_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Risk evidence rows are immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER risk_events_immutable BEFORE UPDATE OR DELETE ON risk_events FOR EACH ROW EXECUTE FUNCTION vsn_prevent_risk_event_mutation();
SQL);
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') DB::unprepared('DROP FUNCTION IF EXISTS vsn_prevent_risk_event_mutation() CASCADE;');
        Schema::dropIfExists('risk_holds');
        Schema::dropIfExists('risk_cases');
        Schema::dropIfExists('risk_events');
        Schema::dropIfExists('risk_profiles');
    }
};
