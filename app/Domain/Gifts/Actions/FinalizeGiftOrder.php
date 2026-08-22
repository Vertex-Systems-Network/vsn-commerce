<?php

namespace App\Domain\Gifts\Actions;

use App\Enums\GiftStatus;
use App\Enums\GiftRewardStatus;
use App\Enums\PaymentStatus;
use App\Models\Gift;
use App\Models\GiftNotification;
use App\Models\GiftSenderReward;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the FinalizeGiftOrder class and its project responsibilities. */
class FinalizeGiftOrder
{
    /** Initializes the FinalizeGiftOrder instance and its dependencies. */
    public function __construct(private readonly RecordGiftSenderProgress $progress) {}

    /** Executes the finalize gift order operation. */
    public function execute(Order $order): ?Gift
    {
        $gift = Gift::query()->where('checkout_session_id', $order->checkout_session_id)->first();
        if (! $gift) return null;

        $gift = DB::transaction(/** Inline callback for this operation. */ function () use ($gift,$order): Gift {
            $gift = Gift::query()->whereKey($gift->id)->lockForUpdate()->firstOrFail();
            $paid = $order->payment_status === PaymentStatus::Paid;
            $status = $paid
                ? ($gift->scheduled_for && $gift->scheduled_for->isFuture() ? GiftStatus::Scheduled : GiftStatus::Processing)
                : GiftStatus::AwaitingPayment;
            $gift->update(['order_id'=>$order->id,'status'=>$status,'paid_at'=>$paid ? ($gift->paid_at ?? now()) : $gift->paid_at]);

            if ($paid && $gift->scheduled_for) {
                foreach ($order->vendorOrders()->get() as $vendorOrder) {
                    $metadata = $vendorOrder->metadata ?? [];
                    $metadata['gift_delivery_target_at'] = $gift->scheduled_for->toIso8601String();
                    $metadata['gift_delivery_constraint'] = 'not_before_target';
                    $metadata['gift_id'] = $gift->public_id;
                    $vendorOrder->update(['metadata'=>$metadata]);
                }
            }

            if ($paid && ! empty(($gift->metadata ?? [])['gift_wrap_reward_id'])) {
                $reward = GiftSenderReward::query()
                    ->where('public_id', ($gift->metadata ?? [])['gift_wrap_reward_id'])
                    ->where('user_id', $gift->sender_user_id)
                    ->lockForUpdate()->first();
                if ($reward && $reward->status === GiftRewardStatus::Reserved && (($reward->metadata ?? [])['reserved_for_checkout'] ?? null) === $gift->checkoutSession?->public_id) {
                    $rewardMetadata = $reward->metadata ?? [];
                    $rewardMetadata['consumed_for_gift'] = $gift->public_id;
                    $rewardMetadata['consumed_at'] = now()->toIso8601String();
                    $reward->update(['status'=>GiftRewardStatus::Consumed,'metadata'=>$rewardMetadata,'consumed_at'=>now()]);
                }
            }

            if ($paid) {
                GiftNotification::query()->firstOrCreate(
                    ['gift_id'=>$gift->id,'type'=>'gift_created'],
                    ['public_id'=>(string)Str::ulid(),'recipient_user_id'=>$gift->recipient_user_id,'status'=>'pending','available_at'=>$gift->scheduled_for ?? now(),
                     'payload'=>['gift_id'=>$gift->public_id,'anonymous'=>$gift->anonymous]],
                );
            }
            return $gift->fresh(['sender','recipient','product.images','variant','order']);
        }, 3);

        if ($order->payment_status === PaymentStatus::Paid && ! $gift->progress_recorded_at) {
            $this->progress->execute($gift->sender, $gift->gift_value_coins, "product-gift:{$gift->public_id}", 'gift', $gift->public_id, 'product_gift', ['order_id'=>$order->public_id]);
            Gift::query()->whereKey($gift->id)->whereNull('progress_recorded_at')->update(['progress_recorded_at'=>now()]);
        }
        return $gift->fresh(['sender','recipient','product.images','variant','order']);
    }
}
