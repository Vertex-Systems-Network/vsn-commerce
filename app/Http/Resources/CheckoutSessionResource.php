<?php

namespace App\Http\Resources;

use App\Models\CheckoutSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Defines the CheckoutSessionResource class and its project responsibilities.
 *
 * @mixin CheckoutSession
 */
class CheckoutSessionResource extends JsonResource
{
    /** Handles to array for the checkout session resource workflow. */
    public function toArray(Request $request): array
    {
        $gift = $this->relationLoaded('gift') ? $this->gift : null;

        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'shippingMethod' => $this->shipping_method,
            'paymentMethod' => $this->payment_method,
            'savedPaymentMethod' => $this->savedPaymentMethod ? (new SavedPaymentMethodResource($this->savedPaymentMethod))->resolve($request) : null,
            'couponCode' => $this->coupon_code,
            'addressId' => $this->address_id,
            'address' => $gift ? ['private' => true, 'recipient' => 'Gift recipient'] : $this->address_snapshot,
            'expiresAt' => $this->expires_at?->toISOString(),
            'expired' => (bool) $this->expires_at?->isPast(),
            'canPlaceOrder' => $this->status->value === 'reserved' && ! $this->expires_at->isPast(),
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'productName' => $item->product_name,
                'variantName' => $item->variant_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'unitPriceMinor' => $item->unit_price_minor,
                'lineTotalMinor' => $item->line_total_minor,
                'selectedOptions' => $item->selected_options ?? new \stdClass,
                'vendor' => $item->vendor?->name,
                'reservation' => $item->reservation ? [
                    'id' => $item->reservation->id,
                    'status' => $item->reservation->status->value,
                    'expiresAt' => $item->reservation->expires_at?->toISOString(),
                ] : null,
            ])->values(),
            'totals' => [
                'subtotalMinor' => $this->subtotal_minor,
                'shippingMinor' => $this->shipping_minor,
                'discountMinor' => $this->discount_minor,
                'platformDiscountMinor' => $this->platform_discount_minor,
                'sellerDiscountMinor' => $this->seller_discount_minor,
                'taxMinor' => $this->tax_minor,
                'taxIncludedMinor' => $this->tax_included_minor,
                'taxAddedMinor' => $this->tax_added_minor,
                'coinRedemptionCoins' => $this->coin_redemption_coins,
                'coinRedemptionMinor' => $this->coin_redemption_minor,
                'totalMinor' => $this->total_minor,
            ],
            'promotions' => $this->metadata['promotions'] ?? [],
            'tax' => $this->metadata['tax'] ?? null,
            'shippingQuote' => $this->metadata['shipping_quote'] ?? null,
            'paymentIntents' => $this->whenLoaded('paymentIntents', fn () => $this->paymentIntents->sortByDesc('id')->map(fn ($intent) => (new PaymentIntentResource($intent))->resolve($request))->values()),
            'activePaymentIntent' => $this->whenLoaded('paymentIntents', function () use ($request) {
                $intent = $this->paymentIntents->sortByDesc('id')->first();

                return $intent ? (new PaymentIntentResource($intent))->resolve($request) : null;
            }),
            'orderId' => $this->order?->public_id,
            'giftId' => $gift?->public_id,
        ];
    }
}
