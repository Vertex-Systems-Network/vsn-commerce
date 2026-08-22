<?php
namespace App\Domain\Affiliate\Actions;

use App\Domain\Wallet\Actions\ReverseWalletTransaction;
use App\Domain\Wallet\Services\WalletService;
use App\Enums\AffiliateCommissionStatus;
use App\Enums\WalletTransactionType;
use App\Models\AffiliateCommission;
use App\Models\AffiliateCommissionRefund;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/** Defines the ReverseOrderAffiliateCommissions class and its project responsibilities. */
class ReverseOrderAffiliateCommissions
{
    /** Initializes the ReverseOrderAffiliateCommissions instance and its dependencies. */
    public function __construct(
        private readonly ReverseWalletTransaction $reverseWalletTransaction,
        private readonly WalletService $wallets,
    ) {}

    /** Executes the reverse order affiliate commissions operation. */
    public function execute(Order $order, string $reason = 'order_refund'): int
    {
        $ids = AffiliateCommission::query()->where('order_id', $order->id)->orderBy('id')->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $reversed = DB::transaction(/** Inline callback for this operation. */ function () use ($id, $order, $reason): bool {
                $commission = AffiliateCommission::query()->whereKey($id)->with(['walletTransaction','beneficiary'])->lockForUpdate()->first();
                if (! $commission || in_array($commission->status, [AffiliateCommissionStatus::Reversed, AffiliateCommissionStatus::Cancelled], true)) return false;

                $alreadyReversedCoins=(int)AffiliateCommissionRefund::query()->where('affiliate_commission_id',$commission->id)->sum('reversed_coins');
                $remainingCoins=max(0,(int)$commission->reward_coins-$alreadyReversedCoins);
                $reversalTxId = null;
                if ($commission->status === AffiliateCommissionStatus::Credited && $commission->walletTransaction && $remainingCoins > 0) {
                    if($alreadyReversedCoins===0){
                        $tx = $this->reverseWalletTransaction->execute(null,$commission->walletTransaction,"affiliate:reversal:{$commission->public_id}",'order',$order->public_id,true);
                    }else{
                        $tx = $this->wallets->debit($commission->beneficiary,$remainingCoins,WalletTransactionType::Reversal,"affiliate:reversal:remaining:{$commission->public_id}",'order',$order->public_id,['original_commission'=>$commission->public_id,'previous_partial_reversals_coins'=>$alreadyReversedCoins],true);
                    }
                    $reversalTxId = $tx->id;
                }

                $commission->update([
                    'status' => AffiliateCommissionStatus::Reversed,
                    'reversal_wallet_transaction_id' => $reversalTxId,
                    'reversed_at' => now(),
                    'metadata' => array_merge($commission->metadata ?? [], ['reversal_reason' => $reason,'partial_reversed_coins'=>$alreadyReversedCoins]),
                ]);
                return true;
            }, 3);
            if ($reversed) $count++;
        }
        return $count;
    }
}
