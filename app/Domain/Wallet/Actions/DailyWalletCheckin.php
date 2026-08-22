<?php
namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Exceptions\WalletException;
use App\Domain\Wallet\Services\WalletService;
use App\Enums\WalletTransactionType;
use App\Models\DailyCheckin;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Defines the DailyWalletCheckin class and its project responsibilities. */
class DailyWalletCheckin
{
    /** Initializes the DailyWalletCheckin instance and its dependencies. */
    public function __construct(private readonly WalletService $wallets) {}

    /** Executes the daily wallet checkin operation. */
    public function execute(User $user): DailyCheckin
    {
        $timezone = $user->profile?->timezone ?: config('app.timezone', 'UTC');
        $today = Carbon::now($timezone)->toDateString();
        $existing = DailyCheckin::query()->where('user_id',$user->id)->where('checkin_date',$today)->first();
        if ($existing) throw new WalletException('Daily coins have already been claimed today.', 'checkin');

        try {
            return DB::transaction(/** Inline callback for this operation. */ function () use ($user,$today,$timezone): DailyCheckin {
                $existing = DailyCheckin::query()->where('user_id',$user->id)->where('checkin_date',$today)->lockForUpdate()->first();
                if ($existing) throw new WalletException('Daily coins have already been claimed today.', 'checkin');
                $yesterday = Carbon::parse($today, $timezone)->subDay()->toDateString();
                $previous = DailyCheckin::query()->where('user_id',$user->id)->where('checkin_date',$yesterday)->first();
                $streak = $previous ? $previous->streak_day + 1 : 1;
                $base = (int) config('vsn.wallet.daily_checkin_coins', 70);
                $bonus = $streak % 7 === 0 ? (int) config('vsn.wallet.seven_day_bonus_coins', 350) : 0;
                $baseTx = $this->wallets->credit($user,$base,WalletTransactionType::DailyCheckin,"checkin:{$user->id}:{$today}",'daily_checkin',$today,['streak_day'=>$streak]);
                $bonusTx = $bonus > 0 ? $this->wallets->credit($user,$bonus,WalletTransactionType::StreakBonus,"checkin-bonus:{$user->id}:{$today}",'daily_checkin',$today,['streak_day'=>$streak]) : null;
                return DailyCheckin::create([
                    'user_id'=>$user->id,'checkin_date'=>$today,'streak_day'=>$streak,'base_reward_coins'=>$base,'bonus_reward_coins'=>$bonus,
                    'base_transaction_id'=>$baseTx->id,'bonus_transaction_id'=>$bonusTx?->id,
                ]);
            },3);
        } catch (QueryException $e) {
            $winner = DailyCheckin::query()->where('user_id',$user->id)->where('checkin_date',$today)->first();
            if ($winner) throw new WalletException('Daily coins have already been claimed today.', 'checkin');
            throw $e;
        }
    }
}
