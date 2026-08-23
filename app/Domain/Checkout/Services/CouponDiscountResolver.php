<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Enums\ReviewCouponStatus;
use App\Models\ReviewRewardCoupon;
use App\Models\User;

/** Defines the CouponDiscountResolver class and its project responsibilities. */
class CouponDiscountResolver
{
    /** Persists terminal review-coupon invalidation before checkout starts its rollbackable transaction. */
    public function preflight(User $user, ?string $couponCode): void
    {
        $couponCode = trim((string) $couponCode);
        if ($couponCode === '') {
            return;
        }

        $coupon = ReviewRewardCoupon::query()
            ->whereRaw('upper(code) = ?', [mb_strtoupper($couponCode)])
            ->with('review.orderItem')
            ->first();

        if (! $coupon) {
            return;
        }
        if ($coupon->user_id !== $user->id) {
            throw new CheckoutValidationException('The review coupon is invalid for this account.', 'couponCode');
        }

        $this->assertNotTerminallyInvalid($coupon);
    }

    /** @return array{discountMinor:int,coupon:?ReviewRewardCoupon} */
    public function resolve(User $user, ?string $couponCode, int $subtotalMinor): array
    {
        $couponCode = trim((string) $couponCode);
        if ($couponCode === '') {
            return ['discountMinor'=>0,'coupon'=>null];
        }

        $coupon = ReviewRewardCoupon::query()
            ->whereRaw('upper(code) = ?', [mb_strtoupper($couponCode)])
            ->with('review.orderItem')
            ->lockForUpdate()
            ->first();

        if (! $coupon || $coupon->user_id !== $user->id) {
            throw new CheckoutValidationException('The review coupon is invalid for this account.', 'couponCode');
        }

        $this->assertNotTerminallyInvalid($coupon);

        if ($coupon->status !== ReviewCouponStatus::Available) {
            throw new CheckoutValidationException('This review coupon is already reserved, used, revoked, or expired.', 'couponCode');
        }

        $discount = min($subtotalMinor, intdiv($subtotalMinor * (int) $coupon->percent_bps, 10_000));

        return ['discountMinor'=>$discount,'coupon'=>$coupon];
    }

    /** Persists terminal expiry/refund state before rejecting the coupon. */
    private function assertNotTerminallyInvalid(ReviewRewardCoupon $coupon): void
    {
        if ($coupon->expires_at?->isPast()) {
            if ($coupon->status !== ReviewCouponStatus::Redeemed) {
                $coupon->update(['status'=>ReviewCouponStatus::Expired]);
            }
            throw new CheckoutValidationException('This review coupon has expired.', 'couponCode');
        }

        $sourceItem = $coupon->review?->orderItem;
        if (! $sourceItem || (int) $sourceItem->refunded_quantity >= (int) $sourceItem->quantity) {
            if ($coupon->status !== ReviewCouponStatus::Redeemed) {
                $coupon->update([
                    'status'=>ReviewCouponStatus::Revoked,
                    'revoked_at'=>now(),
                    'metadata'=>array_merge($coupon->metadata ?? [], ['revoked_reason'=>'source_purchase_fully_refunded']),
                ]);
            }
            throw new CheckoutValidationException('This review coupon is no longer valid because its source purchase was fully refunded.', 'couponCode');
        }
    }
}
