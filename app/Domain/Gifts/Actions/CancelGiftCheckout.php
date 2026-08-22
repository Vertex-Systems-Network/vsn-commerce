<?php

namespace App\Domain\Gifts\Actions;

use App\Domain\Checkout\Actions\ReleaseCheckoutSession;
use App\Domain\Gifts\Exceptions\GiftException;
use App\Enums\CheckoutStatus;
use App\Enums\GiftStatus;
use App\Enums\GiftRewardStatus;
use App\Models\Gift;
use App\Models\GiftSenderReward;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Defines the CancelGiftCheckout class and its project responsibilities. */
class CancelGiftCheckout
{
    /** Initializes the CancelGiftCheckout instance and its dependencies. */
    public function __construct(private readonly ReleaseCheckoutSession $release) {}
    /** Executes the cancel gift checkout operation. */
    public function execute(User $user, Gift $gift): Gift
    {
        if ($gift->sender_user_id !== $user->id) abort(404);
        if ($gift->order_id) throw new GiftException('Placed gift orders must use the normal return/refund workflow.');
        if ($gift->status !== GiftStatus::AwaitingPayment) return $gift;

        DB::transaction(/** Inline callback for this operation. */ function () use ($gift): void {
            $locked = Gift::query()->whereKey($gift->id)->lockForUpdate()->firstOrFail();
            if ($locked->order_id || $locked->status !== GiftStatus::AwaitingPayment) return;
            if ($locked->checkoutSession) $this->release->execute($locked->checkoutSession, CheckoutStatus::Cancelled);
            $rewardId = ($locked->metadata ?? [])['gift_wrap_reward_id'] ?? null;
            if ($rewardId) {
                $reward = GiftSenderReward::query()->where('public_id',$rewardId)->where('user_id',$locked->sender_user_id)->lockForUpdate()->first();
                if ($reward && $reward->status === GiftRewardStatus::Reserved && (($reward->metadata ?? [])['reserved_for_checkout'] ?? null) === $locked->checkoutSession?->public_id) {
                    $metadata = $reward->metadata ?? [];
                    unset($metadata['reserved_for_checkout'], $metadata['reserved_at']);
                    $metadata['reservation_released_at'] = now()->toIso8601String();
                    $reward->update(['status'=>GiftRewardStatus::Available,'metadata'=>$metadata]);
                }
            }
            $locked->update(['status'=>GiftStatus::Cancelled]);
        }, 3);

        return $gift->fresh(['sender','recipient','product.images','variant','order','checkoutSession.paymentIntents']);
    }
}
