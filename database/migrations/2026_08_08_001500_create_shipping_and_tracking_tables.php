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
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
        });

        Schema::create('shipments', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 60)->index();
            $table->string('provider_shipment_id', 190)->nullable();
            $table->string('tracking_number', 190)->nullable();
            $table->string('service_code', 80);
            $table->string('status', 40)->default('pending')->index();
            $table->string('idempotency_key', 190)->unique();
            $table->text('label_url')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('dispatch_not_before_at')->nullable();
            $table->timestamp('dispatch_due_at')->nullable()->index();
            $table->timestamp('delivery_due_at')->nullable()->index();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('rto_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('dispatch_breached_at')->nullable();
            $table->timestamp('delivery_breached_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_shipment_id']);
            $table->unique(['provider', 'tracking_number']);
            $table->index(['vendor_order_id', 'status']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('shipment_items', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->timestamps();
            $table->unique(['shipment_id', 'order_item_id']);
        });

        Schema::create('shipment_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('provider_event_id', 190)->nullable();
            $table->string('status', 40)->index();
            $table->string('code', 100)->nullable();
            $table->text('message')->nullable();
            $table->string('location', 190)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['shipment_id', 'provider_event_id']);
        });

        Schema::create('shipping_webhook_events', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 60);
            $table->string('provider_event_id', 190);
            $table->char('payload_hash', 64);
            $table->boolean('signature_valid')->default(false);
            $table->string('status', 30)->default('received')->index();
            $table->jsonb('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_event_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION vsn_reject_shipment_event_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'shipment_events are immutable';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::statement('CREATE TRIGGER shipment_events_immutable_update BEFORE UPDATE ON shipment_events FOR EACH ROW EXECUTE FUNCTION vsn_reject_shipment_event_mutation()');
            DB::statement('CREATE TRIGGER shipment_events_immutable_delete BEFORE DELETE ON shipment_events FOR EACH ROW EXECUTE FUNCTION vsn_reject_shipment_event_mutation()');
        }
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS shipment_events_immutable_update ON shipment_events');
            DB::statement('DROP TRIGGER IF EXISTS shipment_events_immutable_delete ON shipment_events');
            DB::statement('DROP FUNCTION IF EXISTS vsn_reject_shipment_event_mutation()');
        }
        Schema::dropIfExists('shipping_webhook_events');
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
        Schema::table('vendor_orders', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropColumn(['packed_at', 'dispatched_at', 'delivered_at']);
        });
    }
};
