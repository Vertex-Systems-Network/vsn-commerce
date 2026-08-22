<?php
namespace App\Domain\Finance\Actions;
use App\Enums\VendorPayoutStatus;
use App\Models\User;
use App\Models\VendorPayout;
use App\Models\VendorPayoutAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the ManageVendorPayoutAttempt class and its project responsibilities. */
class ManageVendorPayoutAttempt
{
    /** Initializes the ManageVendorPayoutAttempt instance and its dependencies. */
    public function __construct(private readonly ReconcilePayoutBatch $batches){}

    /** Handles start for the manage vendor payout attempt workflow. */
    public function start(VendorPayout $payout, User $actor, string $provider='manual'): VendorPayoutAttempt
    {
        return DB::transaction(/** Inline callback for this operation. */ function() use ($payout,$actor,$provider): VendorPayoutAttempt {
            $payout=VendorPayout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($payout->status,[VendorPayoutStatus::Approved,VendorPayoutStatus::Processing,VendorPayoutStatus::Failed],true),422,'Payout is not ready for processing.');
            if($payout->status===VendorPayoutStatus::Failed){
                $max=max(0,(int)config('vsn.finance.payout_max_retries',3));
                abort_if((int)$payout->retry_count >= $max,422,'Maximum payout retry attempts reached. Cancel this payout and investigate the payout method.');
                $payout->increment('retry_count');
            }
            $existing=$payout->attempts()->where('status','processing')->latest('attempt_no')->first();
            if($existing)return $existing;
            $attemptNo=(int)$payout->attempts()->max('attempt_no')+1;
            $attempt=$payout->attempts()->create([
                'public_id'=>(string)Str::ulid(),
                'attempt_no'=>$attemptNo,
                'status'=>'processing',
                'provider'=>$provider,
                'idempotency_key'=>"seller-payout:{$payout->public_id}:attempt:{$attemptNo}",
                'initiated_by_user_id'=>$actor->id,
                'started_at'=>now(),
            ]);
            $payout->update(['status'=>VendorPayoutStatus::Processing,'failure_code'=>null,'failure_message'=>null,'failed_at'=>null]);
            if($payout->vendor_payout_batch_id)$this->batches->execute($payout->batch()->firstOrFail());
            return $attempt;
        },3);
    }

    /** Handles fail for the manage vendor payout attempt workflow. */
    public function fail(VendorPayout $payout, User $actor, string $code, string $message, ?string $providerReference=null): VendorPayout
    {
        return DB::transaction(/** Inline callback for this operation. */ function() use ($payout,$actor,$code,$message,$providerReference): VendorPayout {
            $payout=VendorPayout::query()->whereKey($payout->id)->with('attempts')->lockForUpdate()->firstOrFail();
            abort_if(in_array($payout->status,[VendorPayoutStatus::Paid,VendorPayoutStatus::Cancelled],true),422,'Paid or cancelled payouts cannot be failed.');
            $attempt=$payout->attempts()->where('status','processing')->latest('attempt_no')->lockForUpdate()->first();
            if(!$attempt)$attempt=$this->start($payout,$actor);
            $attempt->update(['status'=>'failed','provider_reference'=>$providerReference,'failure_code'=>$code,'failure_message'=>$message,'completed_at'=>now()]);
            $payout->update(['status'=>VendorPayoutStatus::Failed,'failure_code'=>$code,'failure_message'=>$message,'failed_at'=>now(),'provider_reference'=>$providerReference?:$payout->provider_reference]);
            if($payout->vendor_payout_batch_id)$this->batches->execute($payout->batch()->firstOrFail());
            return $payout->fresh(['attempts','items.settlement']);
        },3);
    }

    /** Handles retry for the manage vendor payout attempt workflow. */
    public function retry(VendorPayout $payout, User $actor): VendorPayoutAttempt
    {
        abort_unless($payout->status===VendorPayoutStatus::Failed,422,'Only failed payouts can be retried.');
        return $this->start($payout,$actor);
    }

    /** Handles succeed for the manage vendor payout attempt workflow. */
    public function succeed(VendorPayout $payout, User $actor, string $providerReference): VendorPayoutAttempt
    {
        return DB::transaction(/** Inline callback for this operation. */ function() use ($payout,$actor,$providerReference): VendorPayoutAttempt {
            $payout=VendorPayout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            $attempt=$payout->attempts()->where('status','processing')->latest('attempt_no')->lockForUpdate()->first();
            if(!$attempt)$attempt=$this->start($payout,$actor);
            $attempt->update(['status'=>'succeeded','provider_reference'=>$providerReference,'completed_at'=>now()]);
            return $attempt->fresh();
        },3);
    }
}
