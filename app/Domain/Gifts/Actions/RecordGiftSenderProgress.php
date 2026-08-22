<?php

namespace App\Domain\Gifts\Actions;

use App\Domain\Gifts\Services\GiftLevelService;
use App\Domain\Wallet\Services\WalletService;
use App\Enums\GiftRewardStatus;
use App\Enums\WalletTransactionType;
use App\Models\GiftSenderEvent;
use App\Models\GiftSenderProfile;
use App\Models\GiftSenderReward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the RecordGiftSenderProgress class and its project responsibilities. */
class RecordGiftSenderProgress
{
    /** Initializes the RecordGiftSenderProgress instance and its dependencies. */
    public function __construct(private readonly GiftLevelService $levels, private readonly WalletService $wallets) {}

    /** Executes the record gift sender progress operation. */
    public function execute(User $user, int $giftCoins, string $idempotencyKey, string $referenceType, string $referenceId, string $eventType = 'product_gift', array $metadata = []): GiftSenderEvent
    {
        $existing = GiftSenderEvent::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) return $existing;
        if ($giftCoins <= 0) throw new \InvalidArgumentException('Gift progression value must be positive.');

        [$event, $rewards] = DB::transaction(/** Inline callback for this operation. */ function () use ($user,$giftCoins,$idempotencyKey,$referenceType,$referenceId,$eventType,$metadata): array {
            $existing = GiftSenderEvent::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) return [$existing, []];

            $profile = GiftSenderProfile::query()->firstOrCreate(['user_id'=>$user->id], ['lifetime_gift_coins'=>0,'current_level'=>'starter']);
            $profile = GiftSenderProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $before = (int) $profile->lifetime_gift_coins;
            $after = $before + $giftCoins;
            $event = GiftSenderEvent::create([
                'public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'event_type'=>$eventType,'gift_coins'=>$giftCoins,
                'idempotency_key'=>$idempotencyKey,'reference_type'=>$referenceType,'reference_id'=>$referenceId,'metadata'=>$metadata,'occurred_at'=>now(),
            ]);
            $level = $this->levels->levelFor($after);
            $profile->update(['lifetime_gift_coins'=>$after,'current_level'=>$level['code']]);

            $created = [];
            foreach ($this->levels->thresholdsCrossed($before, $after) as $threshold) {
                foreach ((array) ($threshold['unlock_rewards'] ?? []) as $reward) {
                    $row = GiftSenderReward::query()->firstOrCreate(
                        ['user_id'=>$user->id,'reward_code'=>$reward['code']],
                        ['public_id'=>(string)Str::ulid(),'level'=>$threshold['code'],'status'=>GiftRewardStatus::Available,'source_event_id'=>$event->id,'metadata'=>$reward,'awarded_at'=>now()],
                    );
                    if ($row->wasRecentlyCreated) $created[] = $row;
                }
            }
            return [$event, $created];
        }, 3);

        // Monetary tier rewards are posted through the same immutable wallet ledger.
        foreach ($rewards as $reward) {
            $bonus = (int) (($reward->metadata ?? [])['bonus_coins'] ?? 0);
            if ($bonus > 0) {
                $this->wallets->credit($user, $bonus, WalletTransactionType::GiftLevelReward, "gift-level-reward:{$reward->public_id}", 'gift_sender_reward', $reward->public_id, ['reward_code'=>$reward->reward_code]);
            }
        }
        return $event;
    }
}
