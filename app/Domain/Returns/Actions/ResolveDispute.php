<?php
namespace App\Domain\Returns\Actions;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Enums\DisputeStatus;
use App\Enums\ReturnRequestStatus;
use App\Enums\ReturnResolution;
use App\Models\Dispute;
use App\Models\User;
use Illuminate\Support\Facades\DB;
/** Defines the ResolveDispute class and its project responsibilities. */
class ResolveDispute
{
    /** Executes the resolve dispute operation. */
    public function execute(Dispute $dispute, User $admin, string $outcome, ?string $note=null): Dispute
    {
        return DB::transaction(/** Inline callback for this operation. */ function() use($dispute,$admin,$outcome,$note): Dispute {
            $dispute=Dispute::query()->whereKey($dispute->id)->with('request.items')->lockForUpdate()->firstOrFail();
            if($dispute->status===DisputeStatus::Resolved || $dispute->status===DisputeStatus::Rejected)return $dispute;
            if($outcome==='rejected'){
                $dispute->update(['status'=>DisputeStatus::Rejected,'outcome'=>'rejected','resolution_note'=>$note,'resolved_by_user_id'=>$admin->id,'resolved_at'=>now()]);
                $dispute->request()->update(['status'=>ReturnRequestStatus::Rejected,'resolved_at'=>now()]);
                return $dispute->fresh();
            }
            $resolution=ReturnResolution::tryFrom($outcome); if(!$resolution || $resolution===ReturnResolution::Dispute)throw new ReturnException('Invalid dispute resolution outcome.','outcome');
            foreach($dispute->request->items as $item)$item->update(['approved_minor'=>$item->requested_minor]);
            $dispute->request()->update(['status'=>ReturnRequestStatus::Approved,'resolution'=>$resolution,'approved_minor'=>$dispute->request->requested_minor,'reviewed_at'=>now()]);
            $dispute->update(['status'=>DisputeStatus::Resolved,'outcome'=>$resolution->value,'resolution_note'=>$note,'resolved_by_user_id'=>$admin->id,'resolved_at'=>now()]);
            return $dispute->fresh()->load('request');
        },3);
    }
}
