<?php
namespace App\Domain\Finance\Actions;
use App\Enums\VendorPayoutStatus;
use App\Enums\VendorSettlementStatus;
use App\Models\User;
use App\Models\VendorPayout;
use Illuminate\Support\Facades\DB;
/** Defines the ReviewVendorPayout class and its project responsibilities. */
class ReviewVendorPayout
{
    /** Initializes the ReviewVendorPayout instance and its dependencies. */
    public function __construct(private readonly ReconcilePayoutBatch $batches){}
    /** Executes the review vendor payout operation. */
    public function execute(VendorPayout $payout,User $actor,bool $approve,?string $note=null):VendorPayout
    {return DB::transaction(/** Inline callback for this operation. */ function()use($payout,$actor,$approve,$note):VendorPayout{$payout=VendorPayout::query()->whereKey($payout->id)->with('items.settlement')->lockForUpdate()->firstOrFail();if($payout->status!==VendorPayoutStatus::Requested)return $payout;if($approve){$payout->update(['status'=>VendorPayoutStatus::Approved,'approved_by_user_id'=>$actor->id,'approved_at'=>now(),'metadata'=>array_merge($payout->metadata??[],['review_note'=>$note])]);}else{$this->release($payout);$payout->update(['status'=>VendorPayoutStatus::Cancelled,'approved_by_user_id'=>$actor->id,'cancelled_at'=>now(),'metadata'=>array_merge($payout->metadata??[],['review_note'=>$note])]);}return $payout->fresh('items.settlement');},3);}
    /** Handles cancel for the review vendor payout workflow. */
    public function cancel(VendorPayout $payout,User $actor,?string $note=null):VendorPayout
    {return DB::transaction(/** Inline callback for this operation. */ function()use($payout,$actor,$note):VendorPayout{$payout=VendorPayout::query()->whereKey($payout->id)->with('items.settlement')->lockForUpdate()->firstOrFail();if($payout->status===VendorPayoutStatus::Paid)abort(422,'Paid payouts cannot be cancelled.');if($payout->status===VendorPayoutStatus::Cancelled)return $payout;$this->release($payout);$payout->update(['status'=>VendorPayoutStatus::Cancelled,'cancelled_at'=>now(),'metadata'=>array_merge($payout->metadata??[],['cancel_note'=>$note,'cancelled_by'=>$actor->id])]);if($payout->vendor_payout_batch_id)$this->batches->execute($payout->batch()->firstOrFail());return $payout->fresh('items.settlement');},3);}
    /** Handles release for the review vendor payout workflow. */
    public function release(VendorPayout $payout):void{foreach($payout->items as $item){$s=$item->settlement()->lockForUpdate()->first();if(!$s)continue;$s->update(['payout_reserved_minor'=>max(0,(int)$s->payout_reserved_minor-(int)$item->amount_minor),'status'=>VendorSettlementStatus::Available]);$vo=$s->vendorOrder()->lockForUpdate()->first();if($vo)$vo->update(['payout_reserved_minor'=>max(0,(int)$vo->payout_reserved_minor-(int)$item->amount_minor)]);}}
}
