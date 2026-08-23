<?php

namespace App\Http\Resources;

use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Defines the ShipmentResource class and its project responsibilities.
 *
 * @mixin Shipment
 */
class ShipmentResource extends JsonResource
{
    /** Handles to array for the shipment resource workflow. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'orderId' => $this->order?->public_id,
            'vendorOrderId' => $this->vendorOrder?->public_id,
            'seller' => $this->vendor?->name ?? $this->vendorOrder?->vendor?->name ?? 'Marketplace',
            'provider' => $this->provider,
            'sandboxCanSimulate' => $this->provider === 'sandbox'
                && (bool) config('vsn.shipping.providers.sandbox.simulator_enabled')
                && ! app()->environment('production'),
            'trackingNumber' => $this->tracking_number,
            'providerStatus' => $this->provider_status,
            'providerSyncedAt' => $this->provider_synced_at?->toISOString(),
            'providerSyncError' => $this->provider_sync_error,
            'creationAttempts' => (int) $this->creation_attempts,
            'canCancel' => in_array($this->status->value, ['pending', 'label_created', 'ready_for_pickup'], true),
            'canRetryCreation' => $this->status->value === 'pending' && ! $this->provider_shipment_id,
            'serviceCode' => $this->service_code,
            'status' => $this->status->value,
            'labelUrl' => $this->label_url,
            'estimatedDeliveryAt' => $this->estimated_delivery_at?->toISOString(),
            'dispatchNotBeforeAt' => $this->dispatch_not_before_at?->toISOString(),
            'dispatchDueAt' => $this->dispatch_due_at?->toISOString(),
            'deliveryDueAt' => $this->delivery_due_at?->toISOString(),
            'readyAt' => $this->ready_at?->toISOString(),
            'pickedUpAt' => $this->picked_up_at?->toISOString(),
            'outForDeliveryAt' => $this->out_for_delivery_at?->toISOString(),
            'deliveredAt' => $this->delivered_at?->toISOString(),
            'dispatchSlaBreached' => (bool) $this->dispatch_breached_at,
            'deliverySlaBreached' => (bool) $this->delivery_breached_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'orderItemId' => $item->order_item_id,
                'name' => $item->orderItem?->product_name,
                'variant' => $item->orderItem?->variant_name,
                'quantity' => $item->quantity,
            ])->values()),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'id' => $event->public_id,
                'status' => $event->status->value,
                'code' => $event->code,
                'message' => $event->message,
                'location' => $event->location,
                'occurredAt' => $event->occurred_at?->toISOString(),
            ])->values()),
        ];
    }
}
