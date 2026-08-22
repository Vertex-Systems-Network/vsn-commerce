<?php
namespace App\Domain\Returns\Actions;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Domain\Returns\Services\RefundCalculator;
use App\Enums\ReturnRequestStatus;
use App\Enums\ReturnResolution;
use App\Models\ReturnRequest;
use Illuminate\Support\Facades\DB;
/** Defines the ReviewReturnRequest class and its project responsibilities. */
class ReviewReturnRequest
{
    /** Initializes the ReviewReturnRequest instance and its dependencies. */
    public function __construct(private readonly RefundCalculator $calculator){}
    /** Executes the review return request operation. */
    public function execute(ReturnRequest $request, bool $approve, ?ReturnResolution $resolution=null, ?string $note=null, array $items=[]): ReturnRequest
    {
        return DB::transaction(/** Inline callback for this operation. */ function() use($request,$approve,$resolution,$note,$items): ReturnRequest {
            $request=ReturnRequest::query()->whereKey($request->id)->with('items')->lockForUpdate()->firstOrFail();
            if(!in_array($request->status,[ReturnRequestStatus::Submitted,ReturnRequestStatus::Reviewing,ReturnRequestStatus::Disputed],true)) throw new ReturnException('This request is no longer reviewable.');
            if(!$approve){
                foreach($request->items as $item)$item->update(['approved_quantity'=>0,'approved_minor'=>0]);
                $request->update(['status'=>ReturnRequestStatus::Rejected,'approved_minor'=>0,'reviewed_at'=>now(),'resolved_at'=>now(),'metadata'=>array_merge($request->metadata??[],['review_note'=>$note])]);
                return $request->fresh()->load(['items.orderItem','order','refund','dispute']);
            }
            if($resolution)$request->resolution=$resolution;
            $byId=collect($items)->keyBy(/** Inline callback for this operation. */ fn($row)=>(int)($row['returnRequestItemId']??0));
            $approvedTotal=0;
            foreach($request->items as $item){
                $input=$byId->get($item->id);
                $qty=$input===null?(int)$item->quantity:(int)($input['approvedQuantity']??0);
                if($qty<0 || $qty>(int)$item->quantity)throw new ReturnException('Approved quantity cannot exceed the requested quantity.','items');
                $minor=$this->calculator->portion((int)$item->requested_minor,(int)$item->quantity,0,$qty);
                $approvedTotal+=$minor;
                $item->update([
                    'approved_quantity'=>$qty,
                    'approved_minor'=>$minor,
                    'restock'=>$input===null?(bool)$item->restock:(bool)($input['restock']??true),
                ]);
            }
            if($approvedTotal<=0)throw new ReturnException('Approve at least one return item quantity.','items');
            $request->status=ReturnRequestStatus::Approved;
            $request->approved_minor=$approvedTotal;
            $request->reviewed_at=now();
            $request->metadata=array_merge($request->metadata??[],['review_note'=>$note]);
            $request->save();
            return $request->fresh()->load(['items.orderItem','order','refund','dispute']);
        },3);
    }
}
