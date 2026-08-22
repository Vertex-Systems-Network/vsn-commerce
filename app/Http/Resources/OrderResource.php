<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Defines the OrderResource class and its project responsibilities.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** Handles to array for the order resource workflow. */
    public function toArray(Request $request): array
    {
        $gift = $this->relationLoaded('gift') ? $this->gift : null;

        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'paymentStatus' => $this->payment_status->value,
            'paymentMethod' => $this->payment_method,
            'currency' => $this->currency,
            'placedAt' => $this->placed_at?->toISOString(),
            'deliveredAt' => $this->delivered_at?->toISOString(),
            'returnEligible' => in_array($this->status->value, ['delivered', 'partially_refunded'], true),
            'lifecycle' => $this->lifecycle(),
            'shippingAddress' => $gift ? ['private' => true, 'recipient' => 'Gift recipient'] : $this->shippingAddress,
            'totals' => [
                'subtotalMinor' => $this->subtotal_minor,
                'shippingMinor' => $this->shipping_minor,
                'discountMinor' => $this->discount_minor,
                'platformDiscountMinor' => $this->platform_discount_minor,
                'sellerDiscountMinor' => $this->seller_discount_minor,
                'taxMinor' => $this->tax_minor,
                'taxIncludedMinor' => $this->tax_included_minor,
                'taxAddedMinor' => $this->tax_added_minor,
                'taxRefundedMinor' => $this->tax_refunded_minor,
                'coinRedemptionCoins' => $this->coin_redemption_coins,
                'coinRedemptionMinor' => $this->coin_redemption_minor,
                'totalMinor' => $this->total_minor,
                'refundedMinor' => $this->refunded_minor,
                'cashRefundedMinor' => $this->cash_refunded_minor,
                'coinRefundedCoins' => $this->coin_refunded_coins,
            ],
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'productName' => $item->product_name,
                'variantName' => $item->variant_name,
                'quantity' => $item->quantity,
                'returnedQuantity' => $item->returned_quantity,
                'refundedQuantity' => $item->refunded_quantity,
                'unitPriceMinor' => $item->unit_price_minor,
                'lineTotalMinor' => $item->line_total_minor,
                'taxMinor' => $item->tax_minor,
                'taxIncludedMinor' => $item->tax_included_minor,
                'taxAddedMinor' => $item->tax_added_minor,
                'discountMinor' => (int) ($item->metadata['discount_minor'] ?? 0),
                'promotions' => $item->metadata['promotions'] ?? [],
                'selectedOptions' => $item->selected_options ?? new \stdClass,
            ])->values(),
            'payments' => $this->whenLoaded('paymentIntents', fn () => $this->paymentIntents->map(fn ($intent) => [
                'id' => $intent->public_id,
                'provider' => $intent->provider,
                'status' => $intent->status->value,
                'amountMinor' => $intent->amount_minor,
            ])->values()),
            'returns' => $this->whenLoaded('returnRequests', fn () => $this->returnRequests->map(fn ($return) => [
                'id' => $return->public_id,
                'status' => $return->status->value,
                'resolution' => $return->resolution->value,
                'requestedMinor' => $return->requested_minor,
                'refundStatus' => $return->refund?->status?->value,
            ])->values()),
            'gift' => $gift ? [
                'id' => $gift->public_id,
                'status' => $gift->status->value,
                'anonymous' => $gift->anonymous,
                'giftWrap' => $gift->gift_wrap,
                'scheduledFor' => $gift->scheduled_for?->toISOString(),
                'recipient' => ['name' => $gift->recipient?->name],
            ] : null,
            'shipments' => $this->whenLoaded('shipments', fn () => $this->shipments->map(fn ($shipment) => [
                'id' => $shipment->public_id,
                'seller' => $shipment->vendor?->name ?? 'Marketplace',
                'trackingNumber' => $shipment->tracking_number,
                'provider' => $shipment->provider,
                'serviceCode' => $shipment->service_code,
                'status' => $shipment->status->value,
                'estimatedDeliveryAt' => $shipment->estimated_delivery_at?->toISOString(),
                'dispatchSlaBreached' => (bool) $shipment->dispatch_breached_at,
                'deliverySlaBreached' => (bool) $shipment->delivery_breached_at,
            ])->values()),
            'sellerOrders' => $this->vendorOrders->map(fn ($vendorOrder) => [
                'id' => $vendorOrder->public_id,
                'seller' => $vendorOrder->vendor?->name ?? 'Marketplace',
                'status' => $vendorOrder->status->value,
                'subtotalMinor' => $vendorOrder->subtotal_minor,
                'shippingMinor' => $vendorOrder->shipping_minor,
                'discountMinor' => $vendorOrder->discount_minor,
                'sellerDiscountMinor' => $vendorOrder->seller_discount_minor,
                'platformDiscountMinor' => $vendorOrder->coupon_subsidy_minor,
                'taxMinor' => $vendorOrder->tax_minor,
                'taxIncludedMinor' => $vendorOrder->tax_included_minor,
                'taxAddedMinor' => $vendorOrder->tax_added_minor,
                'totalMinor' => $vendorOrder->total_minor,
                'platformCommissionMinor' => $vendorOrder->platform_commission_minor,
                'sellerPayableMinor' => $vendorOrder->seller_payable_minor,
                'packedAt' => $vendorOrder->packed_at?->toISOString(),
                'dispatchedAt' => $vendorOrder->dispatched_at?->toISOString(),
                'deliveredAt' => $vendorOrder->delivered_at?->toISOString(),
                'shipmentId' => $vendorOrder->relationLoaded('shipments') ? $vendorOrder->shipments->sortByDesc('id')->first()?->public_id : null,
                'items' => $vendorOrder->items->count(),
            ])->values(),
        ];
    }

    /** Handles lifecycle for the order resource workflow. */
    private function lifecycle(): array
    {
        $status = $this->status->value;
        $steps = ['confirmed', 'processing', 'packed', 'shipped', 'out_for_delivery', 'delivered'];
        $terminal = in_array($status, ['cancelled', 'returned', 'refunded', 'partially_refunded'], true);
        $index = array_search($status, $steps, true);

        if ($status === 'pending') {
            $index = -1;
        }
        if ($terminal) {
            $index = max(0, $index === false ? 0 : $index);
        }

        return [
            'current' => $status,
            'progressPercent' => $terminal ? null : (int) round((max(0, (int) $index) / (count($steps) - 1)) * 100),
            'terminal' => $terminal,
            'steps' => collect($steps)->map(fn ($step, $i) => [
                'code' => $step,
                'complete' => ! $terminal && $index !== false && $i <= $index,
                'current' => $step === $status,
            ])->all(),
        ];
    }
}
