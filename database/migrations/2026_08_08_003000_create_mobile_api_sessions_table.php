<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('mobile_api_sessions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('access_token_id')->nullable()->index();
            $table->string('refresh_token_hash', 64)->nullable()->unique();
            $table->string('device_key_hash', 64);
            $table->string('device_name', 120);
            $table->string('platform', 20)->default('android')->index();
            $table->string('app_version', 40)->nullable();
            $table->string('os_version', 80)->nullable();
            $table->text('push_token')->nullable();
            $table->string('push_token_hash', 64)->nullable()->unique();
            $table->string('push_provider', 30)->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('refresh_expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'device_key_hash']);
            $table->foreign('access_token_id')->references('id')->on('personal_access_tokens')->nullOnDelete();
        });

        Schema::create('mobile_oauth_flows', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('provider', 30)->index();
            $table->string('state_hash', 64)->unique();
            $table->string('device_key_hash', 64);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('exchange_code_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('mobile_oauth_flows');
        Schema::dropIfExists('mobile_api_sessions');
    }
};
