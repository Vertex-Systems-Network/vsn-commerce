<?php

namespace App\Http\Resources;

use App\Models\Review;
use App\Models\ReviewHelpfulVote;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Defines the ReviewResource class and its project responsibilities.
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    /** Handles to array for the review resource workflow. */
    public function toArray(Request $request): array
    {
        $name = $this->user?->name ?? 'Verified buyer';
        if ($request->user()?->id !== $this->user_id) {
            $parts = preg_split('/\s+/', trim($name)) ?: [];
            $name = $parts[0] ?? 'Verified buyer';
            if (count($parts) > 1) {
                $name .= ' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.';
            }
        }

        $role = $request->user()?->role;
        $roleValue = $role instanceof BackedEnum ? $role->value : (string) $role;

        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'rating' => $this->rating,
            'text' => $this->body,
            'verifiedPurchase' => $this->verified_purchase,
            'helpfulCount' => (int) $this->helpful_count,
            'helpfulByMe' => $request->user()?->id
                ? ReviewHelpfulVote::query()->where('review_id', $this->id)->where('user_id', $request->user()->id)->exists()
                : false,
            'reportCount' => $this->when(in_array($roleValue, ['support', 'moderator', 'admin', 'super_admin'], true), (int) $this->report_count),
            'sellerReply' => $this->seller_reply ? [
                'text' => $this->seller_reply,
                'repliedAt' => $this->seller_replied_at?->toISOString(),
                'sellerName' => $this->sellerReplier?->name ?? $this->product?->vendor?->name ?? 'Seller',
            ] : null,
            'submittedAt' => $this->submitted_at?->toISOString(),
            'moderatedAt' => $this->moderated_at?->toISOString(),
            'moderationNote' => $this->when($request->user()?->id === $this->user_id, $this->moderation_note),
            'user' => ['name' => $name],
            'product' => [
                'id' => $this->product?->id,
                'slug' => $this->product?->slug,
                'name' => $this->product?->name ?? $this->orderItem?->product_name,
                'image' => $this->product?->images?->first()?->url,
            ],
            'order' => [
                'id' => $this->order?->public_id,
                'orderItemId' => $this->order_item_id,
            ],
            'images' => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url(),
                'alt' => $image->original_name,
            ])->values(),
            'coupon' => $this->whenLoaded('rewardCoupon', fn () => $this->rewardCoupon
                ? new ReviewCouponResource($this->rewardCoupon->loadMissing('review.product', 'review.orderItem'))
                : null),
        ];
    }
}
