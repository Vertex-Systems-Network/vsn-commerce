<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('vendor_payout_methods', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->default('bank_transfer');
            $table->string('label', 100)->nullable();
            $table->string('account_holder_name', 160);
            $table->string('bank_name', 160)->nullable();
            $table->text('account_identifier_cipher');
            $table->string('account_last4', 8);
            $table->text('routing_identifier_cipher')->nullable();
            $table->string('routing_last4', 8)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->char('currency', 3);
            $table->boolean('is_default')->default(false)->index();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['vendor_id', 'revoked_at'], 'vendor_payout_method_active_idx');
        });

        Schema::table('vendor_payouts', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->foreignId('vendor_payout_method_id')->nullable()->after('vendor_id')->constrained('vendor_payout_methods')->nullOnDelete();
            $table->json('payout_method_snapshot')->nullable()->after('amount_minor');
            $table->unsignedInteger('retry_count')->default(0)->after('provider_reference');
            $table->string('failure_code', 100)->nullable()->after('retry_count');
            $table->text('failure_message')->nullable()->after('failure_code');
            $table->timestamp('failed_at')->nullable()->after('paid_at')->index();
        });

        Schema::create('vendor_payout_attempts', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vendor_payout_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('status', 30)->default('processing')->index();
            $table->string('provider', 60)->default('manual');
            $table->string('idempotency_key', 190)->unique();
            $table->string('provider_reference', 190)->nullable()->index();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['vendor_payout_id', 'attempt_no'], 'vendor_payout_attempt_no_uq');
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('vendor_payout_attempts');
        Schema::table('vendor_payouts', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vendor_payout_method_id');
            $table->dropColumn(['payout_method_snapshot','retry_count','failure_code','failure_message','failed_at']);
        });
        Schema::dropIfExists('vendor_payout_methods');
    }
};
