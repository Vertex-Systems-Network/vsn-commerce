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
        Schema::create('product_alerts', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->string('scope_key', 40)->default('product');
            $table->string('status', 20)->default('active')->index();
            $table->unsignedBigInteger('target_price_minor')->nullable();
            $table->unsignedBigInteger('last_observed_price_minor')->nullable();
            $table->unsignedInteger('last_observed_stock')->default(0);
            $table->unsignedBigInteger('last_notified_price_minor')->nullable();
            $table->unsignedInteger('last_notified_stock')->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id','product_id','type','scope_key'], 'product_alert_unique');
            $table->index(['status','type','last_checked_at']);
        });

        Schema::create('product_price_history', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('compare_at_price_minor')->nullable();
            $table->string('source', 30)->default('catalog');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('recorded_at')->useCurrent()->index();
            $table->index(['product_id','product_variant_id','recorded_at'], 'product_price_hist_lookup_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE products ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(name,'') || ' ' || coalesce(sku,'') || ' ' || coalesce(short_description,'') || ' ' || coalesce(description,''))) STORED");
            DB::statement('CREATE INDEX products_search_vector_gin ON products USING GIN (search_vector)');
            DB::statement('CREATE INDEX products_price_published_idx ON products (status, base_price_minor, id)');
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS products_price_published_idx');
            DB::statement('DROP INDEX IF EXISTS products_search_vector_gin');
            DB::statement('ALTER TABLE products DROP COLUMN IF EXISTS search_vector');
        }
        Schema::dropIfExists('product_price_history');
        Schema::dropIfExists('product_alerts');
    }
};
