<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::table('mobile_api_sessions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->string('previous_refresh_token_hash', 64)->nullable()->unique('mobile_prev_refresh_uq')->after('refresh_token_hash');
            $table->unsignedInteger('refresh_generation')->default(0)->after('previous_refresh_token_hash');
            $table->timestamp('last_rotated_at')->nullable()->after('refresh_expires_at');
            $table->timestamp('compromised_at')->nullable()->index('mobile_compromised_idx')->after('last_rotated_at');
            $table->string('compromise_reason', 80)->nullable()->after('compromised_at');
            $table->timestamp('push_token_updated_at')->nullable()->after('push_provider');
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::table('mobile_api_sessions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropUnique('mobile_prev_refresh_uq');
            $table->dropIndex('mobile_compromised_idx');
            $table->dropColumn([
                'previous_refresh_token_hash', 'refresh_generation', 'last_rotated_at',
                'compromised_at', 'compromise_reason', 'push_token_updated_at',
            ]);
        });
    }
};
