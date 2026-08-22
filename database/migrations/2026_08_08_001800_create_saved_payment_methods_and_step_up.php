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
        Schema::create('saved_payment_methods', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 60)->index();
            $table->string('payment_method', 40)->default('card');
            $table->text('provider_token_cipher');
            $table->text('provider_customer_id_cipher')->nullable();
            $table->string('fingerprint_sha256', 64)->nullable();
            $table->string('brand', 40)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('holder_name', 160)->nullable();
            $table->jsonb('billing_address_snapshot')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->boolean('is_default')->default(false)->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'is_default']);
            $table->unique(['user_id', 'provider', 'fingerprint_sha256']);
        });

        Schema::create('security_step_up_sessions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 80)->index();
            $table->string('device_hash', 64)->nullable()->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
        });

        Schema::table('checkout_sessions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->foreignId('saved_payment_method_id')->nullable()->after('payment_method')->constrained('saved_payment_methods')->nullOnDelete();
        });
        Schema::table('payment_intents', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->foreignId('saved_payment_method_id')->nullable()->after('payment_method')->constrained('saved_payment_methods')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX saved_payment_methods_one_default_per_user ON saved_payment_methods (user_id) WHERE is_default = true AND status = 'active'");
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::table('payment_intents', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropConstrainedForeignId('saved_payment_method_id'));
        Schema::table('checkout_sessions', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropConstrainedForeignId('saved_payment_method_id'));
        Schema::dropIfExists('security_step_up_sessions');
        Schema::dropIfExists('saved_payment_methods');
    }
};
