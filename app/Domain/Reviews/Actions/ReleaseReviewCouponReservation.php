<?php

namespace App\Domain\Reviews\Actions;

use App\Enums\ReviewCouponStatus;
use App\Models\CheckoutSession;
use App\Models\ReviewRewardCoupon;

/** Defines the ReleaseReviewCouponReservation class and its project responsibilities. */
class ReleaseReviewCouponReservation
{
    /** Executes the release review coupon reservation operation. */
    public function execute(CheckoutSession $session): void
    {
        $coupon = ReviewRewardCoupon::query()->where('reserved_checkout_session_id', $session->id)->lockForUpdate()->first();
        if (! $coupon || $coupon->status !== ReviewCouponStatus::Reserved) return;
        if ($coupon->expires_at?->isPast()) {
            $coupon->update(['status'=>ReviewCouponStatus::Expired,'reserved_checkout_session_id'=>null,'reserved_at'=>null]);
            return;
        }
        $coupon->update(['status'=>ReviewCouponStatus::Available,'reserved_checkout_session_id'=>null,'reserved_at'=>null]);
    }
}
