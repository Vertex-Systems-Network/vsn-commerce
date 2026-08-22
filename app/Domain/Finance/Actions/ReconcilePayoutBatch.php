<?php
namespace App\Domain\Finance\Actions;
use App\Enums\VendorPayoutStatus;
use App\Models\VendorPayoutBatch;
/** Defines the ReconcilePayoutBatch class and its project responsibilities. */
class ReconcilePayoutBatch
{
    /** Executes the reconcile payout batch operation. */
    public function execute(VendorPayoutBatch $batch):VendorPayoutBatch
    {
        $batch->load('payouts');$paid=$batch->payouts->where('status',VendorPayoutStatus::Paid)->count();$cancelled=$batch->payouts->where('status',VendorPayoutStatus::Cancelled)->count();$failed=$batch->payouts->where('status',VendorPayoutStatus::Failed)->count();
        $terminal=$paid+$cancelled+$failed;
        $status=$paid===$batch->payout_count?'completed':($terminal===$batch->payout_count?'partial_failed':'processing');
        $batch->update(['status'=>$status,'completed_at'=>$status==='processing'?null:now()]);return $batch->fresh('payouts.vendor');
    }
}
