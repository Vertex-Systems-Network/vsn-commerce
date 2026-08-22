<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::table('deployment_runs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->char('composer_lock_sha256', 64)->nullable()->after('artifact_sha256');
            $table->char('npm_lock_sha256', 64)->nullable()->after('composer_lock_sha256');
        });
        Schema::table('production_acceptance_runs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->char('composer_lock_sha256', 64)->nullable()->after('artifact_sha256');
            $table->char('npm_lock_sha256', 64)->nullable()->after('composer_lock_sha256');
        });
        Schema::table('release_candidate_manifests', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->char('composer_lock_sha256', 64)->nullable()->after('artifact_sha256');
            $table->char('npm_lock_sha256', 64)->nullable()->after('composer_lock_sha256');
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::table('release_candidate_manifests', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropColumn(['composer_lock_sha256','npm_lock_sha256']));
        Schema::table('production_acceptance_runs', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropColumn(['composer_lock_sha256','npm_lock_sha256']));
        Schema::table('deployment_runs', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropColumn(['composer_lock_sha256','npm_lock_sha256']));
    }
};
