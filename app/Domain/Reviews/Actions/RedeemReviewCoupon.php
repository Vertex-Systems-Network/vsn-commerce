<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Enums\ReviewCouponStatus;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\ReviewRewardCoupon;
use App\Models\User;

/** Defines the RedeemReviewCoupon class and its project responsibilities. */
class RedeemReviewCoupon
{
    /** Executes the redeem review coupon operation. */
    public function execute(User $user, CheckoutSession $session, Order $order): ?ReviewRewardCoupon
    {
        $code = trim((string) $session->coupon_code);
        if ($code === '') return null;
        $coupon = ReviewRewardCoupon::query()->whereRaw('upper(code) = ?', [mb_strtoupper($code)])->with('review.orderItem')->lockForUpdate()->first();
        if (! $coupon || $coupon->user_id !== $user->id) throw new CheckoutValidationException('The review coupon is invalid for this account.', 'couponCode');
        if ($coupon->status === ReviewCouponStatus::Redeemed && $coupon->redeemed_order_id === $order->id) return $coupon;
        if ($coupon->status !== ReviewCouponStatus::Reserved || $coupon->reserved_checkout_session_id !== $session->id) {
            throw new CheckoutValidationException('This review coupon is not reserved for the current checkout.', 'couponCode');
        }
        if ($coupon->expires_at?->isPast()) throw new CheckoutValidationException('This review coupon expired before the order was placed.', 'couponCode');
        $item = $coupon->review?->orderItem;
        if (! $item || (int) $item->refunded_quantity >= (int) $item->quantity) throw new CheckoutValidationException('The source purchase for this coupon is no longer eligible.', 'couponCode');

        $coupon->update([
            'status'=>ReviewCouponStatus::Redeemed,
            'redeemed_order_id'=>$order->id,
            'redeemed_at'=>now(),
            'reserved_checkout_session_id'=>null,
            'reserved_at'=>null,
        ]);
        return $coupon->fresh();
    }
}
