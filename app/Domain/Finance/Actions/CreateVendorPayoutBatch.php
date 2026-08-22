<?php
namespace App\Domain\Finance\Actions;
use App\Enums\VendorPayoutStatus;
use App\Models\User;
use App\Models\VendorPayout;
use App\Models\VendorPayoutBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the CreateVendorPayoutBatch class and its project responsibilities. */
class CreateVendorPayoutBatch
{
    /** @param array<int,string> $payoutIds */
    public function execute(User $actor,array $payoutIds,?string $providerBatchReference=null):VendorPayoutBatch
    {
        $ids=array_values(array_unique(array_filter($payoutIds)));if(!$ids)abort(422,'Select at least one approved payout.');
        return DB::transaction(/** Inline callback for this operation. */ function()use($actor,$ids,$providerBatchReference):VendorPayoutBatch{
            $payouts=VendorPayout::query()->whereIn('public_id',$ids)->lockForUpdate()->get();if($payouts->count()!==count($ids))abort(422,'One or more payouts were not found.');
            foreach($payouts as $p){if($p->status!==VendorPayoutStatus::Approved)abort(422,'Only approved payouts can enter a payout batch.');if($p->vendor_payout_batch_id)abort(422,'A selected payout already belongs to a batch.');}
            $currencies=$payouts->pluck('currency')->unique();if($currencies->count()!==1)abort(422,'A payout batch cannot mix currencies.');
            $batch=VendorPayoutBatch::create(['public_id'=>(string)Str::ulid(),'created_by_user_id'=>$actor->id,'status'=>'processing','currency'=>$currencies->first(),'total_minor'=>(int)$payouts->sum('amount_minor'),'payout_count'=>$payouts->count(),'provider_batch_reference'=>$providerBatchReference]);
            foreach($payouts as $p)$p->update(['vendor_payout_batch_id'=>$batch->id,'status'=>VendorPayoutStatus::Processing]);return $batch->load('payouts.vendor');
        },3);
    }
}
