<?php
namespace App\Domain\Returns\Actions;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Enums\ReturnRequestStatus;
use App\Models\ReturnRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
/** Defines the CancelReturnRequest class and its project responsibilities. */
class CancelReturnRequest
{
    /** Executes the cancel return request operation. */
    public function execute(User $user, ReturnRequest $request):ReturnRequest
    {
        if($request->user_id!==$user->id)abort(404);
        return DB::transaction(/** Inline callback for this operation. */ function()use($request):ReturnRequest{
            $row=ReturnRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if($row->status!==ReturnRequestStatus::Submitted)throw new ReturnException('Only a submitted return can be cancelled by the customer.');
            $row->update(['status'=>ReturnRequestStatus::Cancelled,'cancelled_at'=>now(),'resolved_at'=>now()]);
            return $row->fresh()->load(['items.orderItem','order','refund.events','dispute']);
        },3);
    }
}
