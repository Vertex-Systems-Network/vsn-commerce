<?php
namespace App\Domain\Returns\Actions;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Domain\Returns\Services\RefundCalculator;
use App\Enums\ReturnRequestStatus;
use App\Enums\ReturnResolution;
use App\Models\ReturnRequest;
use Illuminate\Support\Facades\DB;
/** Defines the ReceiveReturn class and its project responsibilities. */
class ReceiveReturn
{
    /** Initializes the ReceiveReturn instance and its dependencies. */
    public function __construct(private readonly RestockReturnedItems $restock, private readonly ProcessRefund $refunds, private readonly RefundCalculator $calculator) {}
    /** Executes the receive return operation. */
    public function execute(ReturnRequest $request, array $inspectionItems=[], ?string $note=null): ReturnRequest
    {
        $request=DB::transaction(/** Inline callback for this operation. */ function()use($request,$inspectionItems,$note):ReturnRequest{
            $request=ReturnRequest::query()->whereKey($request->id)->with('items')->lockForUpdate()->firstOrFail();
            if(!in_array($request->status,[ReturnRequestStatus::Approved,ReturnRequestStatus::InTransit,ReturnRequestStatus::Received],true))throw new ReturnException('Return is not ready to be received.');
            $byId=collect($inspectionItems)->keyBy(/** Inline callback for this operation. */ fn($row)=>(int)($row['returnRequestItemId']??0));
            $approvedMinor=0;$acceptedTotal=0;
            foreach($request->items as $item){
                $approvedQty=(int)($item->approved_quantity ?: $item->quantity);
                $input=$byId->get($item->id);
                $received=$input===null?$approvedQty:(int)($input['receivedQuantity']??0);
                $accepted=$input===null?$received:(int)($input['acceptedQuantity']??0);
                if($received<0||$received>$approvedQty)throw new ReturnException('Received quantity cannot exceed approved quantity.','items');
                if($accepted<0||$accepted>$received)throw new ReturnException('Accepted quantity cannot exceed received quantity.','items');
                $minor=$this->calculator->portion((int)$item->requested_minor,(int)$item->quantity,0,$accepted);
                $approvedMinor+=$minor;$acceptedTotal+=$accepted;
                $item->update([
                    'received_quantity'=>$received,
                    'accepted_quantity'=>$accepted,
                    'approved_minor'=>$minor,
                    'restock'=>$input===null?(bool)$item->restock:(bool)($input['restock']??$item->restock),
                    'condition'=>$input['condition']??$item->condition,
                    'inspection_note'=>$input['note']??$item->inspection_note,
                ]);
            }
            if($acceptedTotal<=0)throw new ReturnException('No returned quantity was accepted during inspection.','items');
            $request->update([
                'status'=>ReturnRequestStatus::Received,
                'approved_minor'=>$approvedMinor,
                'received_at'=>$request->received_at?:now(),
                'inspection_completed_at'=>now(),
                'metadata'=>array_merge($request->metadata??[],['inspection_note'=>$note]),
            ]);
            return $request->fresh()->load('items');
        },3);
        $this->restock->execute($request);
        if(in_array($request->resolution,[ReturnResolution::OriginalPayment,ReturnResolution::Coins],true))$this->refunds->execute($request->fresh());
        elseif($request->resolution===ReturnResolution::Replacement)$request->update(['status'=>ReturnRequestStatus::Replaced,'resolved_at'=>now()]);
        return $request->fresh()->load(['items.orderItem','order','refund.events','dispute']);
    }
}
