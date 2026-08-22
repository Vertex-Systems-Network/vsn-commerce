<?php
namespace App\Domain\Returns\Actions;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Enums\ReturnRequestStatus;
use App\Models\ReturnRequest;
use App\Models\User;
/** Defines the MarkReturnInTransit class and its project responsibilities. */
class MarkReturnInTransit
{
    /** Executes the mark return in transit operation. */
    public function execute(User $user, ReturnRequest $request, string $tracking, ?string $carrier=null): ReturnRequest
    {
        if($request->user_id!==$user->id) abort(404);
        if($request->status!==ReturnRequestStatus::Approved) throw new ReturnException('Return must be approved before it can be marked as shipped.');
        if(trim($tracking)==='')throw new ReturnException('A return tracking reference is required.','trackingReference');
        $request->update([
            'status'=>ReturnRequestStatus::InTransit,
            'return_tracking_reference'=>trim($tracking),
            'return_carrier'=>$carrier?trim($carrier):null,
            'shipped_at'=>now(),
        ]);
        return $request->fresh()->load(['items.orderItem','order','refund','dispute']);
    }
}
