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
        Schema::table('vendor_orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('coupon_subsidy_minor')->default(0)->after('discount_minor');
            $table->unsignedBigInteger('coupon_subsidy_reversed_minor')->default(0)->after('seller_payable_reversed_minor');
            $table->unsignedBigInteger('seller_recovery_offset_minor')->default(0)->after('coupon_subsidy_reversed_minor');
            $table->unsignedBigInteger('payout_reserved_minor')->default(0)->after('seller_recovery_offset_minor');
            $table->unsignedBigInteger('paid_out_minor')->default(0)->after('payout_reserved_minor');
            $table->timestamp('finance_posted_at')->nullable()->index()->after('paid_out_minor');
        });
        Schema::table('vendor_refund_adjustments', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('coupon_subsidy_reversal_minor')->default(0)->after('seller_payable_reversal_minor');
        });

        Schema::create('finance_journals', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('type', 60)->index();
            $table->string('reference_type', 80)->nullable()->index();
            $table->string('reference_id', 190)->nullable()->index();
            $table->string('idempotency_key', 190)->unique();
            $table->char('currency', 3);
            $table->string('status', 20)->default('posted');
            $table->timestamp('posted_at');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('finance_entries', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_journal_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_code', 100)->index();
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount_minor');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['account_code','vendor_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION vsn_prevent_finance_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Posted finance ledger rows are immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER finance_journals_immutable BEFORE UPDATE OR DELETE ON finance_journals FOR EACH ROW EXECUTE FUNCTION vsn_prevent_finance_mutation();
CREATE TRIGGER finance_entries_immutable BEFORE UPDATE OR DELETE ON finance_entries FOR EACH ROW EXECUTE FUNCTION vsn_prevent_finance_mutation();
SQL);
        }

        Schema::create('vendor_settlements', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vendor_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency', 3);
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('customer_discount_minor')->default(0);
            $table->unsignedBigInteger('coupon_subsidy_minor')->default(0);
            $table->unsignedBigInteger('platform_commission_minor')->default(0);
            $table->unsignedBigInteger('seller_payable_minor')->default(0);
            $table->unsignedBigInteger('seller_payable_reversed_minor')->default(0);
            $table->unsignedBigInteger('seller_recovery_offset_minor')->default(0);
            $table->unsignedBigInteger('payout_reserved_minor')->default(0);
            $table->unsignedBigInteger('paid_out_minor')->default(0);
            $table->string('status', 40)->default('hold_payment')->index();
            $table->timestamp('eligible_at')->nullable()->index();
            $table->timestamp('available_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['vendor_id','status']);
        });

        Schema::create('vendor_payout_batches', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('processing')->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedInteger('payout_count');
            $table->string('provider_batch_reference', 190)->nullable()->unique();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_payouts', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_payout_batch_id')->nullable()->constrained('vendor_payout_batches')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('requested')->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('idempotency_key', 190)->unique();
            $table->string('provider_reference', 190)->nullable()->unique();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['vendor_id','created_at']);
        });
        Schema::create('vendor_payout_items', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_payout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_settlement_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
            $table->unique(['vendor_payout_id','vendor_settlement_id']);
        });

        Schema::create('finance_reconciliation_runs', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('running')->index();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('issues_count')->default(0);
            $table->jsonb('summary')->nullable();
            $table->timestamps();
        });
        Schema::create('finance_reconciliation_issues', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_reconciliation_run_id');
            $table->foreign('finance_reconciliation_run_id', 'fin_recon_issue_run_fk')
                ->references('id')->on('finance_reconciliation_runs')->cascadeOnDelete();
            $table->string('code', 80)->index();
            $table->string('reference_type', 80)->nullable();
            $table->string('reference_id', 190)->nullable();
            $table->bigInteger('expected_minor')->nullable();
            $table->bigInteger('actual_minor')->nullable();
            $table->bigInteger('delta_minor')->nullable();
            $table->text('message');
            $table->timestamp('resolved_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('finance_reconciliation_issues');
        Schema::dropIfExists('finance_reconciliation_runs');
        Schema::dropIfExists('vendor_payout_items');
        Schema::dropIfExists('vendor_payouts');
        Schema::dropIfExists('vendor_payout_batches');
        Schema::dropIfExists('vendor_settlements');
        Schema::dropIfExists('finance_entries');
        Schema::dropIfExists('finance_journals');
        if (DB::getDriverName() === 'pgsql') DB::unprepared('DROP FUNCTION IF EXISTS vsn_prevent_finance_mutation()');
        Schema::table('vendor_refund_adjustments', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropColumn('coupon_subsidy_reversal_minor'));
        Schema::table('vendor_orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropColumn(['coupon_subsidy_minor','coupon_subsidy_reversed_minor','seller_recovery_offset_minor','payout_reserved_minor','paid_out_minor','finance_posted_at']);
        });
    }
};
