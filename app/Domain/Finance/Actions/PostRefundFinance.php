<?php
namespace App\Domain\Finance\Actions;
use App\Domain\Finance\FinanceAccounts;
use App\Enums\FinanceDirection;
use App\Enums\VendorPayoutStatus;
use App\Models\Refund;
use App\Models\VendorPayout;
use App\Models\VendorSettlement;
use Illuminate\Support\Facades\DB;
/** Defines the PostRefundFinance class and its project responsibilities. */
class PostRefundFinance
{
    /** Initializes the PostRefundFinance instance and its dependencies. */
    public function __construct(private readonly PostFinanceJournal $journal,private readonly PostOrderFinance $postOrderFinance,private readonly ReconcilePayoutBatch $batches){}
    /** Executes the post refund finance operation. */
    public function execute(Refund $refund):void
    {
        $this->postOrderFinance->execute($refund->order()->firstOrFail());
        DB::transaction(/** Inline callback for this operation. */ function()use($refund):void{
            $refund=Refund::query()->whereKey($refund->id)->with(['vendorAdjustments.vendorOrder'])->lockForUpdate()->firstOrFail();
            $entries=[];$commission=0;$subsidy=0;$platformTax=0;
            foreach($refund->vendorAdjustments as $a){
                $settlement=VendorSettlement::query()->where('vendor_order_id',$a->vendor_order_id)->lockForUpdate()->first();
                if($settlement){$this->cancelUnpaidPayouts($settlement,$refund->public_id);$settlement->refresh();}
                $sellerReversal=(int)$a->seller_payable_reversal_minor;
                $alreadyReversed=$settlement?(int)$settlement->seller_payable_reversed_minor:0;
                $paid=$settlement?(int)$settlement->paid_out_minor:(int)($a->vendorOrder?->paid_out_minor??0);
                $payableBefore=max(0,(int)($settlement?->seller_payable_minor??$a->vendorOrder?->seller_payable_minor??0)-$alreadyReversed);
                $outstandingLiability=max(0,$payableBefore-$paid);
                $liabilityDebit=min($sellerReversal,$outstandingLiability);$recoveryDebit=max(0,$sellerReversal-$liabilityDebit);
                if($liabilityDebit>0)$entries[]=['account'=>FinanceAccounts::SELLER_PAYABLE,'direction'=>FinanceDirection::Debit->value,'amount'=>$liabilityDebit,'vendor_id'=>$a->vendorOrder?->vendor_id,'metadata'=>['vendor_order_id'=>$a->vendorOrder?->public_id]];
                if($recoveryDebit>0)$entries[]=['account'=>FinanceAccounts::SELLER_RECOVERY,'direction'=>FinanceDirection::Debit->value,'amount'=>$recoveryDebit,'vendor_id'=>$a->vendorOrder?->vendor_id,'metadata'=>['vendor_order_id'=>$a->vendorOrder?->public_id,'reason'=>'refund_after_payout']];
                $commission+=(int)$a->platform_commission_reversal_minor;$subsidy+=(int)$a->coupon_subsidy_reversal_minor;$platformTax+=(int)$a->platform_tax_reversal_minor;
                if($settlement)$settlement->update(['seller_payable_reversed_minor'=>min((int)$settlement->seller_payable_minor,$alreadyReversed+$sellerReversal)]);
            }
            if($platformTax>0)$entries[]=['account'=>FinanceAccounts::SALES_TAX_PAYABLE,'direction'=>FinanceDirection::Debit->value,'amount'=>$platformTax];
            if($commission>0)$entries[]=['account'=>FinanceAccounts::PLATFORM_COMMISSION,'direction'=>FinanceDirection::Debit->value,'amount'=>$commission];
            if($subsidy>0)$entries[]=['account'=>FinanceAccounts::COUPON_SUBSIDY,'direction'=>FinanceDirection::Credit->value,'amount'=>$subsidy];
            if((int)$refund->cash_refund_minor>0)$entries[]=['account'=>FinanceAccounts::PAYMENT_CLEARING,'direction'=>FinanceDirection::Credit->value,'amount'=>(int)$refund->cash_refund_minor];
            if((int)$refund->coin_refund_minor>0)$entries[]=['account'=>FinanceAccounts::COIN_LIABILITY,'direction'=>FinanceDirection::Credit->value,'amount'=>(int)$refund->coin_refund_minor];
            $this->journal->execute('refund',$refund->currency,"finance-refund:{$refund->public_id}",$entries,'refund',$refund->public_id);
        },3);
    }
    /** Handles cancel unpaid payouts for the post refund finance workflow. */
    private function cancelUnpaidPayouts(VendorSettlement $settlement,string $refundId):void
    {
        $payouts=VendorPayout::query()->whereIn('status',[VendorPayoutStatus::Requested->value,VendorPayoutStatus::Approved->value,VendorPayoutStatus::Processing->value])->whereHas('items',/** Inline callback for this operation. */ fn($q)=>$q->where('vendor_settlement_id',$settlement->id))->with('items.settlement.vendorOrder')->lockForUpdate()->get();
        foreach($payouts as $payout){foreach($payout->items as $item){$s=$item->settlement()->lockForUpdate()->first();if(!$s)continue;$s->update(['payout_reserved_minor'=>max(0,(int)$s->payout_reserved_minor-(int)$item->amount_minor)]);$vo=$s->vendorOrder()->lockForUpdate()->first();if($vo)$vo->update(['payout_reserved_minor'=>max(0,(int)$vo->payout_reserved_minor-(int)$item->amount_minor)]);} $payout->update(['status'=>VendorPayoutStatus::Cancelled,'cancelled_at'=>now(),'metadata'=>array_merge($payout->metadata??[],['cancelled_reason'=>'refund_adjustment','refund_id'=>$refundId])]);if($payout->vendor_payout_batch_id)$this->batches->execute($payout->batch()->firstOrFail());}
    }
}
