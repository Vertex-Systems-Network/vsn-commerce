<?php

namespace App\Domain\Gifts\Actions;

use App\Models\GiftNotification;

/** Defines the DispatchGiftNotifications class and its project responsibilities. */
class DispatchGiftNotifications
{
    /** Executes the dispatch gift notifications operation. */
    public function execute(int $limit = 200): int
    {
        $count = 0;
        GiftNotification::query()->where('status','pending')->where('available_at','<=',now())->orderBy('id')->limit($limit)->get()->each(/** Inline callback for this operation. */ function (GiftNotification $notification) use (&$count): void {
            $updated = GiftNotification::query()->whereKey($notification->id)->where('status','pending')->update(['status'=>'delivered','delivered_at'=>now()]);
            if ($updated) {
                $notification->gift()->whereNull('recipient_notified_at')->update(['recipient_notified_at'=>now()]);
                $count++;
            }
        });
        return $count;
    }
}
