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
        Schema::create('notification_preferences', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('channel', 20);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'category', 'channel']);
        });

        Schema::create('marketplace_notifications', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40)->index();
            $table->string('type', 80);
            $table->string('title', 190);
            $table->text('body');
            $table->string('action_url', 500)->nullable();
            $table->string('reference_type', 80)->nullable();
            $table->string('reference_id', 190)->nullable();
            $table->string('dedup_key', 190)->unique();
            $table->jsonb('data')->nullable();
            $table->boolean('in_app_visible')->default(true)->index();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('notification_deliveries', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_notification_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at')->nullable()->index();
            $table->timestampTz('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['marketplace_notification_id', 'channel'], 'notif_delivery_channel_uq');
        });

        Schema::create('conversations', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('thread_key', 190)->unique();
            $table->string('kind', 30)->default('order')->index();
            $table->string('subject', 190);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('open')->index();
            $table->timestampTz('last_message_at')->nullable()->index();
            $table->timestampsTz();
            $table->index(['order_id', 'vendor_order_id', 'kind']);
            $table->unique(['vendor_order_id', 'kind']);
        });

        Schema::create('conversation_participants', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('participant_role', 30)->default('member');
            $table->timestampTz('joined_at')->useCurrent();
            $table->timestampTz('last_read_at')->nullable();
            $table->timestampTz('muted_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'archived_at']);
        });

        Schema::create('conversation_messages', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reply_to_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->text('body')->nullable();
            $table->string('client_id', 190)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['conversation_id', 'created_at']);
            $table->unique(['conversation_id', 'sender_user_id', 'client_id'], 'conv_msg_client_uq');
        });

        Schema::create('message_attachments', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('conversation_message_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestampTz('created_at')->useCurrent();
        });

        // Chat facts are append-only. Read/mute/archive cursors live on participants instead.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION vsn_block_chat_fact_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Conversation messages and attachments cannot be updated';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::statement('CREATE TRIGGER conversation_messages_immutable BEFORE UPDATE ON conversation_messages FOR EACH ROW EXECUTE FUNCTION vsn_block_chat_fact_mutation()');
            DB::statement('CREATE TRIGGER message_attachments_immutable BEFORE UPDATE ON message_attachments FOR EACH ROW EXECUTE FUNCTION vsn_block_chat_fact_mutation()');
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS message_attachments_immutable ON message_attachments');
            DB::statement('DROP TRIGGER IF EXISTS conversation_messages_immutable ON conversation_messages');
            DB::statement('DROP FUNCTION IF EXISTS vsn_block_chat_fact_mutation()');
        }
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('marketplace_notifications');
        Schema::dropIfExists('notification_preferences');
    }
};
