<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration {
    /** Applies this database migration. */
    public function up(): void {
        Schema::create('kyc_verifications', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40)->index();
            $table->string('status', 30)->default('pending')->index();
            $table->string('provider', 50)->default('manual');
            $table->string('provider_reference', 190)->nullable()->index();
            $table->text('document_number_cipher')->nullable();
            $table->string('document_number_last4', 8)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('document_front_path')->nullable();
            $table->string('document_back_path')->nullable();
            $table->string('selfie_path')->nullable();
            $table->string('address_proof_path')->nullable();
            $table->jsonb('provider_payload')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id','type','status']);
        });

        Schema::create('user_devices', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_key_hash', 64);
            $table->string('label', 120)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('last_session_id')->nullable()->index();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('trusted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id','device_key_hash']);
        });

        Schema::create('security_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 80)->index();
            $table->string('severity', 20)->index();
            $table->string('ip_address',45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('session_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('admin_audit_logs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action',190)->index();
            $table->string('method',10);
            $table->string('path',500);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('target_type',190)->nullable();
            $table->string('target_id',190)->nullable();
            $table->string('ip_address',45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_hash',64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION vsn_block_immutable_security_rows() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'immutable audit/security row'; END; $$ LANGUAGE plpgsql;");
            foreach (['security_events','admin_audit_logs'] as $name) {
                DB::unprepared("CREATE TRIGGER {$name}_immutable BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION vsn_block_immutable_security_rows();");
            }
        }
    }

    /** Reverts this database migration. */
    public function down(): void {
        if (DB::getDriverName() === 'pgsql') DB::unprepared('DROP FUNCTION IF EXISTS vsn_block_immutable_security_rows() CASCADE;');
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('kyc_verifications');
    }
};
