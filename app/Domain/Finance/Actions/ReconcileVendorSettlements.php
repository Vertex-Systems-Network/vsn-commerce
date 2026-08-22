<?php
namespace App\Domain\Finance\Actions;
use App\Domain\Finance\FinanceAccounts;
use App\Enums\FinanceDirection;
use App\Enums\PaymentStatus;
use App\Enums\VendorSettlementStatus;
use App\Models\FinanceEntry;
use App\Models\Order;
use App\Models\VendorOrder;
use App\Models\VendorSettlement;
use Illuminate\Support\Facades\DB;
/** Defines the ReconcileVendorSettlements class and its project responsibilities. */
class ReconcileVendorSettlements
{
    /** Initializes the ReconcileVendorSettlements instance and its dependencies. */
    public function __construct(private readonly PostFinanceJournal $journal){}
    /** Executes the reconcile vendor settlements operation. */
    public function execute(?int $vendorId=null):int
    {
        $count=0;$q=VendorSettlement::query();if($vendorId)$q->where('vendor_id',$vendorId);
        $q->orderBy('id')->select('id')->chunkById(100,/** Inline callback for this operation. */ function($rows)use(&$count):void{foreach($rows as $row){DB::transaction(/** Inline callback for this operation. */ function()use($row):void{
            $s=VendorSettlement::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();$vo=VendorOrder::query()->whereKey($s->vendor_order_id)->lockForUpdate()->firstOrFail();$order=Order::query()->whereKey($vo->order_id)->lockForUpdate()->firstOrFail();$status=$s->status;$eligible=$s->eligible_at;
            $payableAfterRefunds=max(0,(int)$s->seller_payable_minor-(int)$s->seller_payable_reversed_minor);
            if($payableAfterRefunds<=0)$status=VendorSettlementStatus::Reversed;
            elseif((int)$s->paid_out_minor >= max(0,$payableAfterRefunds-(int)$s->seller_recovery_offset_minor))$status=VendorSettlementStatus::Paid;
            elseif($order->payment_status!==PaymentStatus::Paid)$status=VendorSettlementStatus::HoldPayment;
            elseif(!($vo->delivered_at ?: $order->delivered_at))$status=VendorSettlementStatus::HoldDelivery;
            else{
                $vendorDeliveredAt=$vo->delivered_at ?: $order->delivered_at;
                $eligible=$eligible?:$vendorDeliveredAt->copy()->addDays((int)config('vsn.finance.payout_hold_days',30));
                if(now()->lt($eligible))$status=VendorSettlementStatus::HoldReturnWindow;
                else{
                    if($s->vendor_id && (int)$s->payout_reserved_minor===0)$this->offsetSellerRecovery($s,$vo);
                    $s->refresh();
                    if($s->remainingPayableMinor()<=0)$status=VendorSettlementStatus::Paid;
                    elseif($s->payout_reserved_minor>0)$status=VendorSettlementStatus::PayoutPending;
                    elseif($s->paid_out_minor>0)$status=VendorSettlementStatus::PartiallyPaid;
                    else$status=VendorSettlementStatus::Available;
                }
            }
            $changes=['status'=>$status,'eligible_at'=>$eligible];if($status===VendorSettlementStatus::Available && !$s->available_at)$changes['available_at']=now();$s->update($changes);
        },3);$count++;}});return $count;
    }
    /** Handles offset seller recovery for the reconcile vendor settlements workflow. */
    private function offsetSellerRecovery(VendorSettlement $s,VendorOrder $vo):void
    {
        $debit=(int)FinanceEntry::query()->where('vendor_id',$s->vendor_id)->where('account_code',FinanceAccounts::SELLER_RECOVERY)->where('direction','debit')->sum('amount_minor');
        $credit=(int)FinanceEntry::query()->where('vendor_id',$s->vendor_id)->where('account_code',FinanceAccounts::SELLER_RECOVERY)->where('direction','credit')->sum('amount_minor');
        $recovery=max(0,$debit-$credit);$available=$s->availableMinor();$offset=min($recovery,$available);if($offset<=0)return;
        $newTotal=(int)$s->seller_recovery_offset_minor+$offset;
        $this->journal->execute('seller_recovery_offset',$s->currency,"finance-recovery-offset:{$s->public_id}:{$newTotal}",[
            ['account'=>FinanceAccounts::SELLER_PAYABLE,'direction'=>FinanceDirection::Debit->value,'amount'=>$offset,'vendor_id'=>$s->vendor_id],
            ['account'=>FinanceAccounts::SELLER_RECOVERY,'direction'=>FinanceDirection::Credit->value,'amount'=>$offset,'vendor_id'=>$s->vendor_id],
        ],'vendor_settlement',$s->public_id);
        $s->update(['seller_recovery_offset_minor'=>$newTotal]);$vo->update(['seller_recovery_offset_minor'=>(int)$vo->seller_recovery_offset_minor+$offset]);
    }
}
