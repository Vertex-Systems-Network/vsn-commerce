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
        Schema::create('checkout_sessions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 120)->unique();
            $table->string('status', 30)->default('reserved')->index();
            $table->char('currency', 3)->default('PKR');
            $table->foreignId('address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->jsonb('address_snapshot');
            $table->string('shipping_method', 60);
            $table->string('payment_method', 60)->default('cod');
            $table->string('coupon_code', 80)->nullable();
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('coin_redemption_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->timestamp('expires_at')->index();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['cart_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX checkout_one_reserved_cart ON checkout_sessions (cart_id) WHERE status = 'reserved'");
        }

        Schema::create('checkout_session_items', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkout_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_item_id')->nullable()->constrained('cart_items')->nullOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_reservation_id')->nullable()->unique()->constrained('inventory_reservations')->nullOnDelete();
            $table->string('product_name', 190);
            $table->string('variant_name', 160);
            $table->string('sku', 120)->nullable();
            $table->unsignedSmallInteger('quantity');
            $table->char('currency', 3);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->jsonb('selected_options')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('checkout_session_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 40)->default('confirmed')->index();
            $table->string('payment_status', 40)->default('pending')->index();
            $table->string('payment_method', 60);
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('coin_redemption_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->timestamp('placed_at');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'placed_at']);
        });

        Schema::create('order_addresses', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->default('shipping');
            $table->string('label', 60)->nullable();
            $table->string('recipient_name', 120);
            $table->string('phone', 40);
            $table->string('line1', 190);
            $table->string('line2', 190)->nullable();
            $table->string('city', 100);
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->char('country_code', 2);
            $table->timestamps();

            $table->unique(['order_id', 'type']);
        });

        Schema::create('vendor_orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 40)->default('confirmed')->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedInteger('commission_bps')->default(0);
            $table->unsignedBigInteger('platform_commission_minor')->default(0);
            $table->unsignedBigInteger('seller_payable_minor')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'vendor_id']);
        });

        Schema::create('order_items', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name', 190);
            $table->string('variant_name', 160);
            $table->string('sku', 120)->nullable();
            $table->unsignedSmallInteger('quantity');
            $table->char('currency', 3);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->jsonb('selected_options')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS checkout_one_reserved_cart');
        }

        Schema::dropIfExists('order_items');
        Schema::dropIfExists('vendor_orders');
        Schema::dropIfExists('order_addresses');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('checkout_session_items');
        Schema::dropIfExists('checkout_sessions');
    }
};
