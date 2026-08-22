<?php
namespace App\Domain\Affiliate\Services;

use App\Enums\AffiliateCommissionStatus;
use App\Models\AffiliateAccount;
use App\Models\AffiliateCommission;
use App\Models\AffiliateRelationship;
use App\Models\User;

/** Defines the AffiliateDashboardService class and its project responsibilities. */
class AffiliateDashboardService
{
    /** Handles summary for the affiliate dashboard service workflow. */
    public function summary(User $user): array
    {
        $account = AffiliateAccount::query()->where('user_id', $user->id)->first();
        $relationship = AffiliateRelationship::query()->where('user_id', $user->id)->first();
        $rates = array_values(config('vsn.affiliate.rates_bps', []));
        $levels = [];
        $parents = [$user->id];
        $totalNetwork = 0;

        for ($level = 1; $level <= min(10, count($rates)); $level++) {
            $members = $parents === [] ? collect() : AffiliateRelationship::query()->whereIn('parent_user_id', $parents)->pluck('user_id')->unique()->values();
            $memberIds = $members->all();
            $totalNetwork += count($memberIds);
            $commission = AffiliateCommission::query()->where('beneficiary_id', $user->id)->where('level_no', $level)->whereNotIn('status', [AffiliateCommissionStatus::Reversed->value, AffiliateCommissionStatus::Cancelled->value]);
            $levels[] = [
                'level' => $level,
                'rateBps' => (int) $rates[$level - 1],
                'rate' => ((int) $rates[$level - 1]) / 100,
                'members' => count($memberIds),
                'eligibleSpendMinor' => (int) (clone $commission)->sum('eligible_spend_minor'),
                'rewardCoins' => (int) (clone $commission)->sum('reward_coins'),
            ];
            $parents = $memberIds;
        }

        $base = AffiliateCommission::query()->where('beneficiary_id', $user->id);
        $sum = /** Inline callback for this operation. */ fn(array $statuses) => (int) (clone $base)->whereIn('status', $statuses)->sum('reward_coins');

        return [
            'enrolled' => (bool) $account,
            'account' => $account ? [
                'status' => $account->status->value,
                'referralCode' => $account->referral_code,
                'referralLink' => rtrim(config('vsn.frontend_url'), '/').'/register?ref='.$account->referral_code,
                'termsVersion' => $account->terms_version,
                'termsAcceptedAt' => $account->terms_accepted_at?->toISOString(),
            ] : null,
            'referrerAttached' => (bool) $relationship,
            'metrics' => [
                'totalNetwork' => $totalNetwork,
                'pendingCoins' => $sum([AffiliateCommissionStatus::Pending->value, AffiliateCommissionStatus::Available->value]),
                'creditedCoins' => $sum([AffiliateCommissionStatus::Credited->value]),
                'lifetimeCoins' => $sum([AffiliateCommissionStatus::Pending->value, AffiliateCommissionStatus::Available->value, AffiliateCommissionStatus::Credited->value]),
            ],
            'levels' => $levels,
            'coinsPerRupee' => (int) config('vsn.coins_per_rupee', 70),
            'holdDays' => (int) config('vsn.affiliate.hold_days', 14),
            'programTermsVersion' => (string) config('vsn.affiliate.terms_version', '2026-08'),
        ];
    }
}
