<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('vendors', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('slug', 190)->unique();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('commission_bps')->default(1000);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 160)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 120)->nullable()->unique();
            $table->string('slug', 190)->unique();
            $table->string('name', 190);
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->char('currency', 3)->default('PKR');
            $table->unsignedBigInteger('base_price_minor');
            $table->unsignedBigInteger('compare_at_price_minor')->nullable();
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedBigInteger('reviews_count')->default(0);
            $table->unsignedBigInteger('sold_count')->default(0);
            $table->boolean('installment_enabled')->default(false);
            $table->boolean('game_enabled')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'category_id']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('product_variants', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 120)->unique();
            $table->string('name', 160);
            $table->jsonb('option_values')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->unsignedBigInteger('compare_at_price_minor')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('product_images', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('alt_text', 190)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('vendors');
    }
};
