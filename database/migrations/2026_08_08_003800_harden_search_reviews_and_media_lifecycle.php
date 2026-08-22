<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('catalog_search_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_hash', 64)->nullable();
            $table->string('query', 160);
            $table->string('normalized_query', 160);
            $table->unsignedInteger('result_count')->default(0);
            $table->json('filters')->nullable();
            $table->timestamp('searched_at')->index();
            $table->timestamps();
            $table->index(['user_id','searched_at'], 'search_user_recent_idx');
            $table->index(['normalized_query','searched_at'], 'search_trending_idx');
            $table->index(['visitor_hash','searched_at'], 'search_visitor_recent_idx');
        });

        Schema::table('reviews', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedInteger('helpful_count')->default(0)->after('verified_purchase');
            $table->unsignedInteger('report_count')->default(0)->after('helpful_count');
            $table->text('seller_reply')->nullable()->after('body');
            $table->foreignId('seller_replied_by')->nullable()->after('seller_reply')->constrained('users')->nullOnDelete();
            $table->timestamp('seller_replied_at')->nullable()->after('seller_replied_by');
        });

        Schema::create('review_helpful_votes', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['review_id','user_id'], 'review_helpful_user_uq');
        });

        Schema::create('review_reports', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 40);
            $table->text('details')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['review_id','user_id'], 'review_report_user_uq');
            $table->index(['review_id','status'], 'review_report_status_idx');
        });

        Schema::table('product_media_assets', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->string('alt_text', 190)->nullable()->after('original_name');
            $table->string('visibility', 20)->default('public')->after('status');
            $table->json('metadata')->nullable()->after('visibility');
        });

        Schema::table('review_images', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->string('sha256', 64)->nullable()->after('size_bytes');
            $table->unsignedInteger('width')->nullable()->after('sha256');
            $table->unsignedInteger('height')->nullable()->after('width');
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::table('review_images', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropColumn(['sha256','width','height']);
        });
        Schema::table('product_media_assets', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropColumn(['alt_text','visibility','metadata']);
        });
        Schema::dropIfExists('review_reports');
        Schema::dropIfExists('review_helpful_votes');
        Schema::table('reviews', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropConstrainedForeignId('seller_replied_by');
            $table->dropColumn(['seller_reply','seller_replied_at','helpful_count','report_count']);
        });
        Schema::dropIfExists('catalog_search_events');
    }
};
