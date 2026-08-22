<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::table('products', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->index(['status','created_at','id'], 'av_products_status_recent_idx');
            $table->index(['status','base_price_minor','id'], 'av_products_price_idx');
            $table->index(['status','rating','reviews_count'], 'av_products_rating_idx');
            $table->index(['status','sold_count','rating'], 'av_products_popular_idx');
        });
        Schema::table('categories', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->index(['is_active','sort_order','name'], 'av_categories_active_sort_idx');
        });
        Schema::table('product_variants', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->index(['product_id','is_active','is_default'], 'av_variants_product_active_idx');
        });
        Schema::table('orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->index(['user_id','status','placed_at'], 'av_orders_user_status_idx');
        });
        Schema::table('reviews', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->index(['status','submitted_at','id'], 'av_reviews_moderation_idx');
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::table('reviews', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropIndex('av_reviews_moderation_idx'));
        Schema::table('orders', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropIndex('av_orders_user_status_idx'));
        Schema::table('product_variants', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropIndex('av_variants_product_active_idx'));
        Schema::table('categories', /** Inline callback for this operation. */ fn (Blueprint $table) => $table->dropIndex('av_categories_active_sort_idx'));
        Schema::table('products', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropIndex('av_products_status_recent_idx');
            $table->dropIndex('av_products_price_idx');
            $table->dropIndex('av_products_rating_idx');
            $table->dropIndex('av_products_popular_idx');
        });
    }
};
