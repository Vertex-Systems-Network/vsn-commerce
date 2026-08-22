<?php

namespace App\Domain\Reviews\Actions;

use App\Enums\ReviewCouponStatus;
use App\Models\Review;
use App\Models\ReviewRewardCoupon;
use Illuminate\Support\Str;

/** Defines the IssueReviewRewardCoupon class and its project responsibilities. */
class IssueReviewRewardCoupon
{
    /** Executes the issue review reward coupon operation. */
    public function execute(Review $review): ReviewRewardCoupon
    {
        $existing = ReviewRewardCoupon::query()->where('review_id', $review->id)->first();
        if ($existing) return $existing;

        do {
            $code = 'VSNREV-'.Str::upper(substr(hash('sha256', (string) Str::uuid()), 0, 10));
        } while (ReviewRewardCoupon::query()->where('code', $code)->exists());

        $days = max(1, (int) config('vsn.reviews.coupon_expiry_days', 90));
        return ReviewRewardCoupon::create([
            'public_id'=>(string) Str::ulid(),
            'code'=>$code,
            'user_id'=>$review->user_id,
            'review_id'=>$review->id,
            'percent_bps'=>(int) config('vsn.reviews.reward_percent_bps', 1000),
            'status'=>ReviewCouponStatus::Available,
            'issued_at'=>now(),
            'expires_at'=>now()->addDays($days),
            'metadata'=>['source'=>'verified_purchase_review'],
        ]);
    }
}
