<?php

namespace App\Domain\Reviews\Actions;

use App\Enums\ReviewCouponStatus;
use App\Models\ReviewRewardCoupon;

/** Defines the ExpireReviewCoupons class and its project responsibilities. */
class ExpireReviewCoupons
{
    /** Executes the expire review coupons operation. */
    public function execute(): int
    {
        return ReviewRewardCoupon::query()
            ->where('status', ReviewCouponStatus::Available->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status'=>ReviewCouponStatus::Expired,'updated_at'=>now()]);
    }
}
