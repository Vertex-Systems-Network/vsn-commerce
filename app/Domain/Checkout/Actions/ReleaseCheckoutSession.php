<?php

namespace App\Domain\Checkout\Actions;

use App\Domain\Inventory\Actions\ReleaseInventoryReservation;
use App\Domain\Wallet\Actions\ReleaseWalletHold;
use App\Domain\Reviews\Actions\ReleaseReviewCouponReservation;
use App\Domain\Promotions\Actions\ReleaseCheckoutPromotions;
use App\Enums\WalletHoldStatus;
use App\Enums\CheckoutStatus;
use App\Enums\PaymentIntentStatus;
use App\Models\CheckoutSession;
use Illuminate\Support\Facades\DB;

/** Defines the ReleaseCheckoutSession class and its project responsibilities. */
class ReleaseCheckoutSession
{
    /** Initializes the ReleaseCheckoutSession instance and its dependencies. */
    public function __construct(
        private readonly ReleaseInventoryReservation $releaseReservation,
        private readonly ReleaseWalletHold $releaseWalletHold,
        private readonly ReleaseReviewCouponReservation $releaseReviewCoupon,
        private readonly ReleaseCheckoutPromotions $releasePromotions,
    ) {}

    /** Executes the release checkout session operation. */
    public function execute(CheckoutSession $session, CheckoutStatus $status = CheckoutStatus::Cancelled): CheckoutSession
    {
        return DB::transaction(/** Inline callback for this operation. */ function () use ($session, $status): CheckoutSession {
            $session = CheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== CheckoutStatus::Reserved) {
                return $session->load(['items.reservation','walletHold']);
            }

            $session->load(['items.reservation','walletHold']);
            foreach ($session->items as $item) {
                if ($item->reservation) {
                    $this->releaseReservation->execute($item->reservation);
                }
            }

            if ($session->walletHold) {
                $this->releaseWalletHold->execute($session->walletHold, $status === CheckoutStatus::Expired ? WalletHoldStatus::Expired : WalletHoldStatus::Released);
            }

            $this->releaseReviewCoupon->execute($session);
            $this->releasePromotions->execute($session);

            $session->paymentIntents()
                ->whereIn('status', [
                    PaymentIntentStatus::Creating->value,
                    PaymentIntentStatus::RequiresAction->value,
                    PaymentIntentStatus::Authorized->value,
                ])
                ->update(['status' => $status === CheckoutStatus::Expired ? PaymentIntentStatus::Expired->value : PaymentIntentStatus::Cancelled->value]);

            $session->update([
                'status' => $status,
                'cancelled_at' => $status === CheckoutStatus::Cancelled ? now() : null,
            ]);

            return $session->fresh()->load(['items.reservation','walletHold']);
        }, 3);
    }
}
