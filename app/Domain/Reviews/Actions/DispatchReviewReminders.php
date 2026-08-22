<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Notifications\Actions\PublishMarketplaceNotification;
use App\Domain\Reviews\Services\ReviewEligibility;
use App\Enums\ReviewReminderStatus;
use App\Models\ReviewReminder;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/** Defines the DispatchReviewReminders class and its project responsibilities. */
class DispatchReviewReminders
{
    /** Initializes the DispatchReviewReminders instance and its dependencies. */
    public function __construct(private readonly ReviewEligibility $eligibility, private readonly PublishMarketplaceNotification $publish) {}

    /** Executes the dispatch review reminders operation. */
    public function execute(int $limit = 200): int
    {
        $cutoff = now()->subDays(max(0, (int) config('vsn.reviews.reminder_delay_days', 2)));
        $processed = 0;
        User::query()->whereNotNull('email_verified_at')->orderBy('id')->chunkById(100, /** Inline callback for this operation. */ function ($users) use ($cutoff, $limit, &$processed): void {
            foreach ($users as $user) {
                if ($processed >= $limit) return;
                $items = $this->eligibility->query($user)
                    ->whereHas('order', /** Inline callback for this operation. */ fn ($query) => $query->where('delivered_at', '<=', $cutoff))
                    ->whereDoesntHave('reviewReminder')
                    ->with(['order','product'])
                    ->limit($limit - $processed)->get();
                foreach ($items as $item) {
                    $reminder = ReviewReminder::query()->firstOrCreate(['order_item_id'=>$item->id],[
                        'user_id'=>$user->id,'order_id'=>$item->order_id,'product_id'=>$item->product_id,
                        'status'=>ReviewReminderStatus::Scheduled,'scheduled_for'=>now(),
                    ]);
                    if ($reminder->status !== ReviewReminderStatus::Scheduled) continue;
                    try {
                        $notification=$this->publish->execute(
                            $user,'reviews','review.reminder','How was your purchase?',
                            "Review {$item->product?->name} from order {$item->order?->public_id} and unlock your verified-review reward.",
                            "review-reminder:{$item->id}",'/reviews','order_item',(string)$item->id,
                            ['orderId'=>$item->order?->public_id,'productId'=>$item->product_id]
                        );
                        $reminder->update(['status'=>ReviewReminderStatus::Queued,'queued_at'=>now(),'attempts'=>$reminder->attempts + 1,'metadata'=>array_merge($reminder->metadata??[],['marketplace_notification_id'=>$notification?->public_id])]);
                        $processed++;
                    } catch (\Throwable $exception) {
                        $reminder->update(['status'=>ReviewReminderStatus::Failed,'attempts'=>$reminder->attempts + 1,'last_error'=>$exception->getMessage()]);
                        Log::warning('Review reminder could not be published.', ['order_item_id'=>$item->id,'error'=>$exception->getMessage()]);
                    }
                }
            }
        });
        return $processed;
    }
}
