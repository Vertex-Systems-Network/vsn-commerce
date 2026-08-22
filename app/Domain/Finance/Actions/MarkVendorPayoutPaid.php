<?php
namespace App\Domain\Finance\Actions;
use App\Domain\Finance\FinanceAccounts;
use App\Enums\FinanceDirection;
use App\Enums\VendorPayoutStatus;
use App\Enums\VendorSettlementStatus;
use App\Models\VendorPayout;
use Illuminate\Support\Facades\DB;
/** Defines the MarkVendorPayoutPaid class and its project responsibilities. */
class MarkVendorPayoutPaid
{
    /** Initializes the MarkVendorPayoutPaid instance and its dependencies. */
    public function __construct(private readonly PostFinanceJournal $journal,private readonly ReconcilePayoutBatch $batches,private readonly ManageVendorPayoutAttempt $attempts){}
    /** Executes the mark vendor payout paid operation. */
    public function execute(VendorPayout $payout,string $providerReference,?\App\Models\User $actor=null):VendorPayout
    {return DB::transaction(/** Inline callback for this operation. */ function()use($payout,$providerReference,$actor):VendorPayout{$payout=VendorPayout::query()->whereKey($payout->id)->with('items.settlement')->lockForUpdate()->firstOrFail();if($payout->status===VendorPayoutStatus::Paid)return $payout;if(!in_array($payout->status,[VendorPayoutStatus::Approved,VendorPayoutStatus::Processing],true))abort(422,'Payout must be approved before it can be marked paid.');
        $this->journal->execute('vendor_payout',$payout->currency,"finance-payout:{$payout->public_id}",[
            ['account'=>FinanceAccounts::SELLER_PAYABLE,'direction'=>FinanceDirection::Debit->value,'amount'=>(int)$payout->amount_minor,'vendor_id'=>$payout->vendor_id],
            ['account'=>FinanceAccounts::PAYMENT_CLEARING,'direction'=>FinanceDirection::Credit->value,'amount'=>(int)$payout->amount_minor],
        ],'vendor_payout',$payout->public_id,['provider_reference'=>$providerReference]);
        foreach($payout->items as $item){$s=$item->settlement()->lockForUpdate()->firstOrFail();$s->update(['payout_reserved_minor'=>max(0,(int)$s->payout_reserved_minor-(int)$item->amount_minor),'paid_out_minor'=>(int)$s->paid_out_minor+(int)$item->amount_minor]);$vo=$s->vendorOrder()->lockForUpdate()->first();if($vo)$vo->update(['payout_reserved_minor'=>max(0,(int)$vo->payout_reserved_minor-(int)$item->amount_minor),'paid_out_minor'=>(int)$vo->paid_out_minor+(int)$item->amount_minor]);$remaining=$s->remainingPayableMinor();$s->update(['status'=>$remaining<=0?VendorSettlementStatus::Paid:VendorSettlementStatus::PartiallyPaid]);}
        if($actor)$this->attempts->succeed($payout,$actor,$providerReference);
        $payout->update(['status'=>VendorPayoutStatus::Paid,'provider_reference'=>$providerReference,'failure_code'=>null,'failure_message'=>null,'failed_at'=>null,'paid_at'=>now()]);if($payout->vendor_payout_batch_id)$this->batches->execute($payout->batch()->firstOrFail());return $payout->fresh('items.settlement');},3);}
}
