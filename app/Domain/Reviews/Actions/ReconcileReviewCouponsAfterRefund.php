<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Checkout\Actions\ReleaseCheckoutSession;
use App\Enums\CheckoutStatus;
use App\Enums\ReviewCouponStatus;
use App\Models\CheckoutSession;
use App\Models\Refund;
use App\Models\ReviewRewardCoupon;
use Illuminate\Support\Facades\DB;

/** Defines the ReconcileReviewCouponsAfterRefund class and its project responsibilities. */
class ReconcileReviewCouponsAfterRefund
{
    /** Initializes the ReconcileReviewCouponsAfterRefund instance and its dependencies. */
    public function __construct(private readonly ReleaseCheckoutSession $releaseCheckout) {}

    /** Executes the reconcile review coupons after refund operation. */
    public function execute(Refund $refund): int
    {
        $refund->loadMissing('request.items.orderItem');
        $fullyRefundedItemIds = $refund->request->items
            ->filter(/** Inline callback for this operation. */ fn ($row) => $row->orderItem && ((int)$row->orderItem->fresh()->refunded_quantity >= (int)$row->orderItem->quantity))
            ->pluck('order_item_id')->all();
        if ($fullyRefundedItemIds === []) return 0;

        $releaseIds = [];
        $count = DB::transaction(/** Inline callback for this operation. */ function () use ($fullyRefundedItemIds, &$releaseIds): int {
            $coupons = ReviewRewardCoupon::query()
                ->whereHas('review', /** Inline callback for this operation. */ fn ($query) => $query->whereIn('order_item_id', $fullyRefundedItemIds))
                ->whereIn('status', [ReviewCouponStatus::Available->value, ReviewCouponStatus::Reserved->value])
                ->lockForUpdate()->get();
            foreach ($coupons as $coupon) {
                if ($coupon->reserved_checkout_session_id) $releaseIds[] = $coupon->reserved_checkout_session_id;
                $coupon->update(['status'=>ReviewCouponStatus::Revoked,'revoked_at'=>now(),'reserved_checkout_session_id'=>null,'reserved_at'=>null,'metadata'=>array_merge($coupon->metadata ?? [], ['revoked_reason'=>'source_purchase_fully_refunded'])]);
            }
            return $coupons->count();
        }, 3);

        foreach (array_unique($releaseIds) as $id) {
            $session = CheckoutSession::query()->find($id);
            if ($session && $session->status === CheckoutStatus::Reserved) $this->releaseCheckout->execute($session);
        }
        return $count;
    }
}
