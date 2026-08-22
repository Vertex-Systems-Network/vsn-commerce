<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration {
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('payment_provider_customers', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 80);
            $table->text('provider_customer_id_cipher');
            $table->timestamps();
            $table->unique(['user_id','provider']);
        });

        Schema::create('provider_runtime_statuses', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->string('provider_type', 40);
            $table->string('provider_code', 80);
            $table->string('status', 30)->default('unknown');
            $table->boolean('production_ready')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('message')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_type','provider_code']);
            $table->index(['status','checked_at']);
        });

        Schema::create('provider_reconciliation_runs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('provider_type', 40);
            $table->string('provider_code', 80);
            $table->string('status', 30)->default('running');
            $table->unsignedInteger('checked_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('details')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['provider_type','provider_code','started_at'], 'provider_recon_started_idx');
        });

        Schema::create('kyc_webhook_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 80);
            $table->string('provider_event_id', 190);
            $table->string('payload_sha256', 64);
            $table->string('status', 30)->default('received');
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider','provider_event_id']);
        });

    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('kyc_webhook_events');
        Schema::dropIfExists('payment_provider_customers');
        Schema::dropIfExists('provider_reconciliation_runs');
        Schema::dropIfExists('provider_runtime_statuses');
    }
};
