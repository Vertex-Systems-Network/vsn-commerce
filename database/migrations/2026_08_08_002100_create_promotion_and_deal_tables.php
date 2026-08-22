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
        Schema::create('promotions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 190);
            $table->string('slug', 190)->unique();
            $table->string('kind', 30)->default('automatic')->index(); // automatic, flash, coupon
            $table->string('status', 30)->default('draft')->index(); // draft, active, paused, ended
            $table->string('discount_type', 20); // percent, fixed
            $table->unsignedInteger('percent_bps')->nullable();
            $table->unsignedBigInteger('fixed_minor')->nullable();
            $table->unsignedBigInteger('minimum_subtotal_minor')->default(0);
            $table->string('stacking_mode', 20)->default('stackable'); // stackable, exclusive
            $table->boolean('can_stack_with_coupon')->default(true);
            $table->boolean('can_stack_with_review_coupon')->default(false);
            $table->string('funding_mode', 20)->default('platform'); // platform, seller, shared
            $table->unsignedInteger('platform_share_bps')->default(10000);
            $table->integer('priority')->default(0)->index();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->boolean('applies_to_gifts')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at', 'ends_at']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('promotion_scopes', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 20); // all, product, category
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index(['promotion_id', 'scope_type']);
        });

        Schema::create('promotion_codes', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80)->unique();
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('promotion_usages', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('promotion_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('checkout_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('reserved')->index(); // reserved/redeemed/released
            $table->unsignedBigInteger('discount_minor');
            $table->unsignedBigInteger('platform_funded_minor')->default(0);
            $table->unsignedBigInteger('seller_funded_minor')->default(0);
            $table->timestamp('reserved_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->unique(['promotion_id', 'checkout_session_id']);
            $table->index(['promotion_id', 'status']);
            $table->index(['user_id', 'promotion_id', 'status']);
        });

        Schema::create('checkout_promotion_allocations', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkout_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('checkout_session_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('promotion_usage_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_type', 30); // promotion, review_reward
            $table->string('source_reference', 100)->nullable();
            $table->unsignedBigInteger('discount_minor');
            $table->unsignedBigInteger('platform_funded_minor')->default(0);
            $table->unsignedBigInteger('seller_funded_minor')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['checkout_session_id', 'checkout_session_item_id'], 'checkout_promo_alloc_item_idx');
        });

        Schema::table('checkout_sessions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_discount_minor')->default(0)->after('discount_minor');
            $table->unsignedBigInteger('seller_discount_minor')->default(0)->after('platform_discount_minor');
        });
        Schema::table('orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_discount_minor')->default(0)->after('discount_minor');
            $table->unsignedBigInteger('seller_discount_minor')->default(0)->after('platform_discount_minor');
        });
        Schema::table('vendor_orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('seller_discount_minor')->default(0)->after('discount_minor');
        });
        Schema::table('vendor_settlements', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('seller_discount_minor')->default(0)->after('customer_discount_minor');
        });
        Schema::table('vendor_refund_adjustments', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('seller_discount_reversal_minor')->default(0)->after('refund_minor');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION vsn_prevent_promotion_allocation_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Checkout promotion allocations are immutable snapshots';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER checkout_promotion_allocations_immutable BEFORE UPDATE OR DELETE ON checkout_promotion_allocations FOR EACH ROW EXECUTE FUNCTION vsn_prevent_promotion_allocation_mutation();
SQL);
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS vsn_prevent_promotion_allocation_mutation() CASCADE');
        }
        Schema::table('vendor_refund_adjustments', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropColumn('seller_discount_reversal_minor'));
        Schema::table('vendor_settlements', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropColumn('seller_discount_minor'));
        Schema::table('vendor_orders', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropColumn('seller_discount_minor'));
        Schema::table('orders', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropColumn(['platform_discount_minor', 'seller_discount_minor']));
        Schema::table('checkout_sessions', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropColumn(['platform_discount_minor', 'seller_discount_minor']));
        Schema::dropIfExists('checkout_promotion_allocations');
        Schema::dropIfExists('promotion_usages');
        Schema::dropIfExists('promotion_codes');
        Schema::dropIfExists('promotion_scopes');
        Schema::dropIfExists('promotions');
    }
};
