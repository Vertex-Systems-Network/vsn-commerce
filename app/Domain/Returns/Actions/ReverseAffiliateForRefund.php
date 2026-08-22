<?php
namespace App\Domain\Returns\Actions;
use App\Domain\Wallet\Services\WalletService;
use App\Enums\AffiliateCommissionStatus;
use App\Enums\WalletTransactionType;
use App\Models\AffiliateCommission;
use App\Models\AffiliateCommissionRefund;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
/** Defines the ReverseAffiliateForRefund class and its project responsibilities. */
class ReverseAffiliateForRefund
{
    /** Initializes the ReverseAffiliateForRefund instance and its dependencies. */
    public function __construct(private readonly WalletService $wallets) {}
    /** Executes the reverse affiliate for refund operation. */
    public function execute(Refund $refund): void
    {
        $order=$refund->order()->with('affiliateCommissions.beneficiary')->firstOrFail();
        $eligibleRefund=min((int)$refund->amount_minor,max(0,(int)$order->subtotal_minor-(int)$order->discount_minor));
        foreach($order->affiliateCommissions as $commission){
            DB::transaction(/** Inline callback for this operation. */ function() use($refund,$commission,$eligibleRefund): void {
                $c=AffiliateCommission::query()->whereKey($commission->id)->with('beneficiary')->lockForUpdate()->firstOrFail();
                if(AffiliateCommissionRefund::query()->where('affiliate_commission_id',$c->id)->where('refund_id',$refund->id)->exists())return;
                $priorMinor=(int)AffiliateCommissionRefund::query()->where('affiliate_commission_id',$c->id)->sum('refunded_eligible_minor');
                $priorCoins=(int)AffiliateCommissionRefund::query()->where('affiliate_commission_id',$c->id)->sum('reversed_coins');
                $newMinor=min((int)$c->eligible_spend_minor,$priorMinor+$eligibleRefund);
                $target=$c->eligible_spend_minor>0?intdiv((int)$c->reward_coins*$newMinor,(int)$c->eligible_spend_minor):0;
                $delta=max(0,$target-$priorCoins); $walletTx=null;
                if($delta>0 && $c->status===AffiliateCommissionStatus::Credited){
                    $walletTx=$this->wallets->debit($c->beneficiary,$delta,WalletTransactionType::Reversal,"affiliate:refund:{$refund->public_id}:{$c->public_id}",'refund',$refund->public_id,['affiliate_commission'=>$c->public_id],true);
                }
                AffiliateCommissionRefund::create(['affiliate_commission_id'=>$c->id,'refund_id'=>$refund->id,'refunded_eligible_minor'=>min($eligibleRefund,max(0,(int)$c->eligible_spend_minor-$priorMinor)),'reversed_coins'=>$delta,'wallet_transaction_id'=>$walletTx?->id]);
                if($target >= (int)$c->reward_coins && $c->status!==AffiliateCommissionStatus::Credited)$c->update(['status'=>AffiliateCommissionStatus::Reversed,'reversed_at'=>now()]);
            },3);
        }
    }
}
