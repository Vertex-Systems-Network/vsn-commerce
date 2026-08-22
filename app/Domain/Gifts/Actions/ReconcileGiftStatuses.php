<?php

namespace App\Domain\Gifts\Actions;

use App\Enums\GiftStatus;
use App\Enums\OrderStatus;
use App\Models\Gift;

/** Defines the ReconcileGiftStatuses class and its project responsibilities. */
class ReconcileGiftStatuses
{
    /** Executes the reconcile gift statuses operation. */
    public function execute(int $limit = 300): int
    {
        $changed = 0;
        Gift::query()
            ->whereNotNull('order_id')
            ->whereNotIn('status', [GiftStatus::Cancelled->value, GiftStatus::Refunded->value, GiftStatus::Fulfilled->value])
            ->with('order')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(/** Inline callback for this operation. */ function (Gift $gift) use (&$changed): void {
                $order = $gift->order;
                if (! $order) return;

                $next = match ($order->status) {
                    OrderStatus::Delivered => GiftStatus::Fulfilled,
                    OrderStatus::Refunded, OrderStatus::Returned => GiftStatus::Refunded,
                    OrderStatus::Cancelled => GiftStatus::Cancelled,
                    default => $gift->scheduled_for && $gift->scheduled_for->isFuture()
                        ? GiftStatus::Scheduled
                        : GiftStatus::Processing,
                };

                if ($gift->status !== $next) {
                    $gift->update(['status'=>$next]);
                    $changed++;
                }
            });

        return $changed;
    }
}
