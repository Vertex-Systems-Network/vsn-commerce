<?php
namespace App\Domain\Affiliate\Actions;

use App\Enums\AffiliateAccountStatus;
use App\Enums\AffiliateCommissionStatus;
use App\Enums\PaymentStatus;
use App\Models\AffiliateAccount;
use App\Models\AffiliateCommission;
use App\Models\AffiliateRelationship;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the AccrueAffiliateCommissions class and its project responsibilities. */
class AccrueAffiliateCommissions
{
    /** Executes the accrue affiliate commissions operation. */
    public function execute(Order $order): Collection
    {
        return DB::transaction(/** Inline callback for this operation. */ function () use ($order): Collection {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->affiliate_accrued_at) {
                return AffiliateCommission::query()->where('order_id', $order->id)->orderBy('level_no')->get();
            }
            if ($order->payment_status !== PaymentStatus::Paid) return collect();

            $eligibleMinor = max(0, (int) $order->subtotal_minor - (int) $order->discount_minor);
            $rates = array_values(config('vsn.affiliate.rates_bps', []));
            $coinsPerRupee = max(1, (int) config('vsn.coins_per_rupee', 70));
            $holdDays = max(0, (int) config('vsn.affiliate.hold_days', 14));
            $parentId = (int) (AffiliateRelationship::query()->where('user_id', $order->user_id)->value('parent_user_id') ?? 0);
            $seen = [$order->user_id => true];

            for ($level = 1; $level <= min(10, count($rates)) && $parentId; $level++) {
                if (isset($seen[$parentId])) break;
                $seen[$parentId] = true;
                $rateBps = (int) $rates[$level - 1];
                $accountActive = AffiliateAccount::query()
                    ->where('user_id', $parentId)
                    ->where('status', AffiliateAccountStatus::Active->value)
                    ->exists();

                if ($eligibleMinor > 0 && $rateBps > 0 && $accountActive) {
                    $numerator = $eligibleMinor * $rateBps * $coinsPerRupee;
                    $rewardCoins = intdiv($numerator + 500_000, 1_000_000);
                    if ($rewardCoins > 0) {
                        AffiliateCommission::query()->firstOrCreate(
                            ['order_id' => $order->id, 'level_no' => $level],
                            [
                                'public_id' => (string) Str::ulid(),
                                'buyer_id' => $order->user_id,
                                'beneficiary_id' => $parentId,
                                'rate_bps' => $rateBps,
                                'currency' => $order->currency,
                                'eligible_spend_minor' => $eligibleMinor,
                                'reward_coins' => $rewardCoins,
                                'status' => AffiliateCommissionStatus::Pending,
                                'available_at' => now()->addDays($holdDays),
                                'metadata' => ['order_public_id' => $order->public_id],
                            ]
                        );
                    }
                }

                $parentId = (int) (AffiliateRelationship::query()->where('user_id', $parentId)->value('parent_user_id') ?? 0);
            }

            $order->forceFill(['affiliate_accrued_at' => now()])->save();
            return AffiliateCommission::query()->where('order_id', $order->id)->orderBy('level_no')->get();
        }, 3);
    }
}
