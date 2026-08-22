<?php
namespace App\Domain\Affiliate\Actions;

use App\Domain\Wallet\Services\WalletService;
use App\Domain\Risk\Services\RiskGate;
use App\Enums\AffiliateAccountStatus;
use App\Enums\AffiliateCommissionStatus;
use App\Enums\WalletTransactionType;
use App\Models\AffiliateAccount;
use App\Models\AffiliateCommission;
use App\Models\AffiliateCommissionRefund;
use Illuminate\Support\Facades\DB;

/** Defines the CreditAvailableAffiliateCommissions class and its project responsibilities. */
class CreditAvailableAffiliateCommissions
{
    /** Initializes the CreditAvailableAffiliateCommissions instance and its dependencies. */
    public function __construct(private readonly WalletService $wallets, private readonly RiskGate $risk) {}

    /** Executes the credit available affiliate commissions operation. */
    public function execute(int $limit = 500): int
    {
        $ids = AffiliateCommission::query()->where('status', AffiliateCommissionStatus::Available->value)->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $credited = DB::transaction(/** Inline callback for this operation. */ function () use ($id): bool {
                $row = AffiliateCommission::query()->whereKey($id)->with(['beneficiary','order'])->lockForUpdate()->first();
                if (! $row || $row->status !== AffiliateCommissionStatus::Available) return false;
                $active = AffiliateAccount::query()->where('user_id',$row->beneficiary_id)->where('status',AffiliateAccountStatus::Active->value)->exists();
                if (! $active) return false;
                if ($this->risk->held($row->beneficiary,'affiliate')) return false;
                $reversed=(int)AffiliateCommissionRefund::query()->where('affiliate_commission_id',$row->id)->sum('reversed_coins');
                $net=max(0,$row->reward_coins-$reversed);
                if ($net === 0) { $row->update(['status'=>AffiliateCommissionStatus::Reversed,'reversed_at'=>now()]); return true; }
                $tx = $this->wallets->credit(
                    $row->beneficiary,
                    $net,
                    WalletTransactionType::AffiliateCommission,
                    "affiliate:commission:{$row->public_id}",
                    'affiliate_commission',
                    $row->public_id,
                    ['order_id'=>$row->order?->public_id,'level'=>$row->level_no,'rate_bps'=>$row->rate_bps]
                );
                $row->update(['status'=>AffiliateCommissionStatus::Credited,'wallet_transaction_id'=>$tx->id,'credited_at'=>now()]);
                return true;
            }, 3);
            if ($credited) $count++;
        }
        return $count;
    }
}
