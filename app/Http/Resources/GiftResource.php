<?php

namespace App\Http\Resources;

use App\Models\Gift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Defines the GiftResource class and its project responsibilities.
 *
 * @mixin Gift
 */
class GiftResource extends JsonResource
{
    /** Handles to array for the gift resource workflow. */
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->id;
        $isRecipient = $viewerId === $this->recipient_user_id;
        $hiddenUntil = $isRecipient && $this->scheduled_for && $this->scheduled_for->isFuture() && ! $this->recipient_notified_at;

        return [
            'id' => $this->public_id,
            'direction' => $viewerId === $this->sender_user_id ? 'sent' : 'received',
            'status' => $this->status->value,
            'sender' => $isRecipient && $this->anonymous ? ['name' => 'Anonymous'] : ['name' => $this->sender?->name],
            'recipient' => $viewerId === $this->sender_user_id ? ['name' => $this->recipient?->name] : null,
            'message' => $hiddenUntil ? null : $this->message,
            'anonymous' => $this->anonymous,
            'giftWrap' => $this->gift_wrap,
            'scheduledFor' => $this->scheduled_for?->toISOString(),
            'recipientNotifiedAt' => $this->recipient_notified_at?->toISOString(),
            'product' => [
                'id' => $this->product?->id,
                'slug' => $this->product?->slug,
                'name' => $this->product?->name,
                'image' => $this->product?->images?->first()?->url,
                'variant' => $this->variant?->name,
            ],
            'giftValueCoins' => $this->gift_value_coins,
            'totals' => [
                'productValueMinor' => $this->product_value_minor,
                'giftWrapMinor' => $this->gift_wrap_minor,
                'giftWrapListMinor' => (int) (($this->metadata ?? [])['gift_wrap_list_minor'] ?? $this->gift_wrap_minor),
                'giftWrapDiscountMinor' => (int) (($this->metadata ?? [])['gift_wrap_discount_minor'] ?? 0),
                'giftValueMinor' => $this->gift_value_minor,
            ],
            'checkoutId' => $viewerId === $this->sender_user_id ? $this->checkoutSession?->public_id : null,
            'paymentMethod' => $viewerId === $this->sender_user_id ? $this->checkoutSession?->payment_method : null,
            'giftWrapRewardApplied' => $viewerId === $this->sender_user_id ? ! empty(($this->metadata ?? [])['gift_wrap_reward_id']) : null,
            'paymentIntent' => $viewerId === $this->sender_user_id
                && $this->checkoutSession?->relationLoaded('paymentIntents')
                && $this->checkoutSession->paymentIntents->isNotEmpty()
                    ? (function (): array {
                        $intent = $this->checkoutSession->paymentIntents->sortByDesc('id')->first();

                        return [
                            'id' => $intent->public_id,
                            'status' => $intent->status->value,
                            'provider' => $intent->provider,
                            'amountMinor' => $intent->amount_minor,
                            'sandboxCanSimulate' => $intent->provider === 'sandbox'
                                && (bool) config('vsn.payments.providers.sandbox.simulator_enabled')
                                && ! app()->isProduction(),
                        ];
                    })()
                    : null,
            'canCancel' => $viewerId === $this->sender_user_id && ! $this->order_id && $this->status->value === 'awaiting_payment',
            'orderId' => $this->order?->public_id,
            'paymentStatus' => $this->order?->payment_status?->value,
            'orderStatus' => $this->order?->status?->value,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
