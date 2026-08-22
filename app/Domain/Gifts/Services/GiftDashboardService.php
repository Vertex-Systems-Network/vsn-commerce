<?php

namespace App\Domain\Gifts\Services;

use App\Models\Gift;
use App\Models\GiftSenderProfile;
use App\Models\GiftSenderReward;
use App\Models\User;

/** Defines the GiftDashboardService class and its project responsibilities. */
class GiftDashboardService
{
    /** Initializes the GiftDashboardService instance and its dependencies. */
    public function __construct(private readonly GiftLevelService $levels) {}
    /** Handles for user for the gift dashboard service workflow. */
    public function forUser(User $user): array
    {
        $profile = GiftSenderProfile::query()->firstOrCreate(['user_id'=>$user->id], ['lifetime_gift_coins'=>0,'current_level'=>'starter']);
        return [
            'profile'=>[
                'lifetimeGiftCoins'=>(int)$profile->lifetime_gift_coins,
                'level'=>$this->levels->levelFor((int)$profile->lifetime_gift_coins),
            ],
            'rewards'=>GiftSenderReward::query()->where('user_id',$user->id)->latest('awarded_at')->get(),
            'sent'=>Gift::query()->where('sender_user_id',$user->id)->with(['sender','recipient','product.images','variant','order','checkoutSession.paymentIntents'])->latest()->limit(50)->get(),
            'received'=>Gift::query()->where('recipient_user_id',$user->id)->with(['sender','recipient','product.images','variant','order','checkoutSession.paymentIntents'])->latest()->limit(50)->get(),
        ];
    }
}
