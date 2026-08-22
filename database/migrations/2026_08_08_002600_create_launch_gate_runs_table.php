<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('launch_gate_runs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('environment', 40);
            $table->string('release', 80)->nullable();
            $table->string('status', 24)->index();
            $table->unsignedInteger('blockers_count')->default(0);
            $table->unsignedInteger('warnings_count')->default(0);
            $table->jsonb('checks');
            $table->timestamp('ran_at')->index();
            $table->timestamps();
            $table->index(['status', 'ran_at']);
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('launch_gate_runs');
    }
};
