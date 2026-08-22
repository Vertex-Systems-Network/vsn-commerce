<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Returns\Actions\CreateReturnRequest as CreateReturn;
use App\Domain\Returns\Actions\CancelReturnRequest;
use App\Domain\Returns\Actions\MarkReturnInTransit;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Enums\ReturnResolution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Returns\CreateReturnRequest;
use App\Http\Requests\Returns\ShipReturnRequest;
use App\Http\Resources\ReturnRequestResource;
use App\Models\Order;
use App\Models\ReturnRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
/** Defines the ReturnController class and its project responsibilities. */
class ReturnController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): AnonymousResourceCollection { return ReturnRequestResource::collection(ReturnRequest::query()->where('user_id',$request->user()->id)->with(['order','items.orderItem','refund.events','dispute'])->latest('submitted_at')->paginate(20)); }
    /** Handles the store request for this resource. */
    public function store(CreateReturnRequest $request, CreateReturn $action): ReturnRequestResource|JsonResponse
    {
        $data=$request->validated(); $order=Order::query()->where('public_id',$data['orderId'])->firstOrFail();
        try{$row=$action->execute($request->user(),$order,ReturnResolution::from($data['resolution']),$data['reason'],$data['details']??null,$data['items']??[]);}catch(ReturnException $e){return $this->error($e);}
        return new ReturnRequestResource($row);
    }
    /** Handles the show request for this resource. */
    public function show(Request $request, ReturnRequest $returnRequest): ReturnRequestResource { abort_unless($returnRequest->user_id===$request->user()->id,404); return new ReturnRequestResource($returnRequest->load(['order','items.orderItem','refund.events','dispute'])); }
    /** Handles ship for the return controller workflow. */
    public function ship(ShipReturnRequest $request, ReturnRequest $returnRequest, MarkReturnInTransit $action): ReturnRequestResource|JsonResponse { try{$row=$action->execute($request->user(),$returnRequest,$request->validated('trackingReference'),$request->validated('carrier'));}catch(ReturnException $e){return $this->error($e);} return new ReturnRequestResource($row); }
    /** Handles cancel for the return controller workflow. */
    public function cancel(Request $request, ReturnRequest $returnRequest, CancelReturnRequest $action): ReturnRequestResource|JsonResponse { try{$row=$action->execute($request->user(),$returnRequest);}catch(ReturnException $e){return $this->error($e);} return new ReturnRequestResource($row); }
    /** Handles error for the return controller workflow. */
    private function error(ReturnException $e): JsonResponse{return response()->json(['message'=>$e->getMessage(),'errors'=>[$e->field=>[$e->getMessage()]]],422);}
}
