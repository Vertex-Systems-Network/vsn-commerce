<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new /** Defines an anonymous class for this operation. */ class extends Migration {
    /** Applies this database migration. */
    public function up(): void {
        Schema::create('wishlist_items', /** Inline callback for this operation. */ function(Blueprint $table): void {
            $table->id();$table->ulid('public_id')->unique();$table->foreignId('user_id')->constrained()->cascadeOnDelete();$table->foreignId('product_id')->constrained()->cascadeOnDelete();$table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();$table->string('scope_key',50)->default('product');$table->timestamps();
            $table->unique(['user_id','product_id','scope_key'],'wishlist_user_product_scope_unique');$table->index(['product_id','created_at']);
        });
        Schema::create('product_views', /** Inline callback for this operation. */ function(Blueprint $table): void {
            $table->id();$table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$table->string('visitor_hash',64)->nullable()->index();$table->foreignId('product_id')->constrained()->cascadeOnDelete();$table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();$table->string('source',40)->default('product_detail');$table->jsonb('metadata')->nullable();$table->timestamp('viewed_at')->useCurrent()->index();$table->timestamps();
            $table->index(['product_id','viewed_at']);$table->index(['user_id','viewed_at']);
        });
        Schema::create('product_media_assets', /** Inline callback for this operation. */ function(Blueprint $table): void {
            $table->id();$table->ulid('public_id')->unique();$table->foreignId('product_id')->constrained()->cascadeOnDelete();$table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();$table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();$table->string('disk',60);$table->string('path',1024);$table->string('original_name',255)->nullable();$table->string('mime_type',100);$table->unsignedBigInteger('byte_size');$table->string('sha256',64);$table->unsignedInteger('width')->nullable();$table->unsignedInteger('height')->nullable();$table->string('status',20)->default('active')->index();$table->unsignedSmallInteger('sort_order')->default(0);$table->timestamps();
            $table->unique(['product_id','sha256'],'product_media_product_sha_unique');$table->index(['product_id','status','sort_order']);
        });
        Schema::table('product_images', /** Inline callback for this operation. */ function(Blueprint $table): void {$table->foreignId('media_asset_id')->nullable()->after('product_variant_id')->constrained('product_media_assets')->nullOnDelete();$table->string('source',20)->default('legacy_url')->after('url');});
        if(DB::getDriverName()==='pgsql'){
            DB::statement('CREATE INDEX product_views_product_recent_idx ON product_views (product_id, viewed_at DESC)');
            DB::statement('CREATE INDEX wishlist_items_user_recent_idx ON wishlist_items (user_id, created_at DESC)');
        }
    }
    /** Reverts this database migration. */
    public function down(): void {
        if(DB::getDriverName()==='pgsql'){DB::statement('DROP INDEX IF EXISTS product_views_product_recent_idx');DB::statement('DROP INDEX IF EXISTS wishlist_items_user_recent_idx');}
        Schema::table('product_images',/** Inline callback for this operation. */ function(Blueprint $table):void{$table->dropConstrainedForeignId('media_asset_id');$table->dropColumn('source');});
        Schema::dropIfExists('product_media_assets');Schema::dropIfExists('product_views');Schema::dropIfExists('wishlist_items');
    }
};
