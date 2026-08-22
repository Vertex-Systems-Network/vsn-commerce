<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Enums\ReviewCouponStatus;
use App\Models\CheckoutSession;
use App\Models\ReviewRewardCoupon;
use App\Models\User;

/** Defines the ReserveReviewCoupon class and its project responsibilities. */
class ReserveReviewCoupon
{
    /** Executes the reserve review coupon operation. */
    public function execute(User $user, ReviewRewardCoupon $coupon, CheckoutSession $session): ReviewRewardCoupon
    {
        $coupon = ReviewRewardCoupon::query()->whereKey($coupon->id)->with('review.orderItem')->lockForUpdate()->firstOrFail();
        if ($coupon->user_id !== $user->id) throw new CheckoutValidationException('This coupon belongs to another account.', 'couponCode');
        if ($coupon->expires_at?->isPast()) {
            if ($coupon->status !== ReviewCouponStatus::Redeemed) $coupon->update(['status'=>ReviewCouponStatus::Expired]);
            throw new CheckoutValidationException('This review coupon has expired.', 'couponCode');
        }
        if ($coupon->status === ReviewCouponStatus::Reserved && $coupon->reserved_checkout_session_id === $session->id) return $coupon;
        if ($coupon->status !== ReviewCouponStatus::Available) throw new CheckoutValidationException('This review coupon is no longer available.', 'couponCode');

        $item = $coupon->review?->orderItem;
        if (! $item || (int) $item->refunded_quantity >= (int) $item->quantity) {
            $coupon->update(['status'=>ReviewCouponStatus::Revoked,'revoked_at'=>now(),'metadata'=>array_merge($coupon->metadata ?? [], ['revoked_reason'=>'source_purchase_fully_refunded'])]);
            throw new CheckoutValidationException('This review coupon is no longer valid because the source purchase was fully refunded.', 'couponCode');
        }

        $coupon->update(['status'=>ReviewCouponStatus::Reserved,'reserved_checkout_session_id'=>$session->id,'reserved_at'=>now()]);
        return $coupon->fresh();
    }
}
