<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Checkout\Actions\ReleaseCheckoutSession;
use App\Domain\Reviews\Exceptions\ReviewException;
use App\Enums\CheckoutStatus;
use App\Enums\ReviewCouponStatus;
use App\Enums\ReviewStatus;
use App\Models\CheckoutSession;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Defines the ModerateReview class and its project responsibilities. */
class ModerateReview
{
    /** Initializes the ModerateReview instance and its dependencies. */
    public function __construct(private readonly RecalculateProductReviewAggregate $aggregate, private readonly ReleaseCheckoutSession $releaseCheckout) {}

    /** Executes the moderate review operation. */
    public function execute(Review $review, User $moderator, ReviewStatus $status, ?string $note = null): Review
    {
        if ($status === ReviewStatus::Pending) throw new ReviewException('Moderation must approve or reject the review.', 'status');
        $releaseSessionId = null;
        $productId = $review->product_id;

        $review = DB::transaction(/** Inline callback for this operation. */ function () use ($review, $moderator, $status, $note, &$releaseSessionId): Review {
            $review = Review::query()->whereKey($review->id)->with('rewardCoupon')->lockForUpdate()->firstOrFail();
            $review->update(['status'=>$status,'moderated_at'=>now(),'moderated_by'=>$moderator->id,'moderation_note'=>$note]);
            if ($status === ReviewStatus::Rejected && $review->rewardCoupon) {
                $coupon = $review->rewardCoupon;
                if (in_array($coupon->status, [ReviewCouponStatus::Available, ReviewCouponStatus::Reserved], true)) {
                    $releaseSessionId = $coupon->reserved_checkout_session_id;
                    $coupon->update(['status'=>ReviewCouponStatus::Revoked,'revoked_at'=>now(),'reserved_checkout_session_id'=>null,'reserved_at'=>null,'metadata'=>array_merge($coupon->metadata ?? [], ['revoked_reason'=>'review_rejected'])]);
                }
            }
            return $review->fresh()->load(['images','rewardCoupon','product','moderator']);
        }, 3);

        if ($releaseSessionId) {
            $session = CheckoutSession::query()->find($releaseSessionId);
            if ($session && $session->status === CheckoutStatus::Reserved) $this->releaseCheckout->execute($session);
        }
        $this->aggregate->execute($productId);
        return $review->fresh()->load(['images','rewardCoupon','product','moderator']);
    }
}
