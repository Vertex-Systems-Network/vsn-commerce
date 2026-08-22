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
        Schema::create('carts', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('guest_token')->nullable()->unique();
            $table->string('status', 30)->default('active')->index();
            $table->char('currency', 3)->default('PKR');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('cart_items', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->char('currency', 3)->default('PKR');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('compare_at_price_minor')->nullable();
            $table->jsonb('selected_options')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['cart_id', 'product_variant_id']);
        });

        // PostgreSQL enforces a single active cart per authenticated user.
        // SQLite is retained for fast feature tests, where the action layer provides the same invariant.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX carts_one_active_user ON carts (user_id) WHERE user_id IS NOT NULL AND status = 'active'");
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS carts_one_active_user');
        }

        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
