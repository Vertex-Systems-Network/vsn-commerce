<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Returns\Actions\ProcessRefund;
use App\Domain\Returns\Actions\ReceiveReturn;
use App\Domain\Returns\Actions\ResolveDispute;
use App\Domain\Returns\Actions\ReviewReturnRequest;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Enums\ReturnResolution;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Returns\ConfirmManualRefundRequest;
use App\Http\Requests\Returns\InspectReturnRequest;
use App\Http\Requests\Returns\ResolveDisputeRequest;
use App\Http\Requests\Returns\ReviewReturnRequest as ReviewRequest;
use App\Http\Resources\ReturnRequestResource;
use App\Models\Dispute;
use App\Models\Refund;
use App\Models\ReturnRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the AdminReturnController class and its project responsibilities. */
class AdminReturnController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        $this->admin($request);$data=$request->validate(['status'=>['nullable','string'],'q'=>['nullable','string','max:120']]);
        $query=ReturnRequest::query()->with(['user:id,name,email','order','items.orderItem','refund.events','dispute'])->latest('submitted_at');
        if(!empty($data['status']))$query->where('status',$data['status']);
        if(!empty($data['q'])){$q=trim($data['q']);$query->where(/** Inline callback for this operation. */ function($builder)use($q){$builder->where('public_id','like',"%{$q}%")->orWhereHas('order',/** Inline callback for this operation. */ fn($o)=>$o->where('public_id','like',"%{$q}%"))->orWhereHas('user',/** Inline callback for this operation. */ fn($u)=>$u->where('email','like',"%{$q}%")->orWhere('name','like',"%{$q}%"));});}
        $rows=$query->paginate(30);
        return response()->json(['data'=>['items'=>$rows->getCollection()->map(/** Inline callback for this operation. */ fn($row)=>$this->row($request,$row))->values(),'meta'=>['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage()]]]);
    }
    /** Handles the show request for this resource. */
    public function show(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->admin($request);$returnRequest->load(['user:id,name,email','order.shippingAddress','items.orderItem','refund.events.actor:id,name,email','dispute']);
        return response()->json(['data'=>$this->row($request,$returnRequest)]);
    }
    /** Handles review for the admin return controller workflow. */
    public function review(ReviewRequest $request, ReturnRequest $returnRequest, ReviewReturnRequest $action): ReturnRequestResource|JsonResponse
    {
        $this->admin($request);$d=$request->validated();
        try{$row=$action->execute($returnRequest,(bool)$d['approve'],isset($d['resolution'])?ReturnResolution::from($d['resolution']):null,$d['note']??null,$d['items']??[]);}catch(ReturnException $e){return $this->error($e);}
        return new ReturnRequestResource($row->load(['order','items.orderItem','refund.events','dispute']));
    }
    /** Handles receive for the admin return controller workflow. */
    public function receive(InspectReturnRequest $request, ReturnRequest $returnRequest, ReceiveReturn $action): ReturnRequestResource|JsonResponse
    {
        $this->admin($request);$d=$request->validated();try{$row=$action->execute($returnRequest,$d['items']??[],$d['note']??null);}catch(ReturnException $e){return $this->error($e);}return new ReturnRequestResource($row);
    }
    /** Handles retry refund for the admin return controller workflow. */
    public function retryRefund(Request $request, Refund $refund, ProcessRefund $action): JsonResponse
    {
        $this->admin($request);try{$row=$action->execute($refund->request()->firstOrFail());}catch(ReturnException $e){return $this->error($e);}catch(\Throwable $e){return response()->json(['message'=>$e->getMessage()],422);}return response()->json(['data'=>$this->refundRow($row)]);
    }
    /** Handles confirm manual for the admin return controller workflow. */
    public function confirmManual(ConfirmManualRefundRequest $request, Refund $refund, ProcessRefund $action): JsonResponse
    {
        $this->admin($request);$d=$request->validated();try{$row=$action->confirmManual($refund,$d['reference'],$request->user()->id,$d['note']??null);}catch(ReturnException $e){return $this->error($e);}return response()->json(['data'=>$this->refundRow($row)]);
    }
    /** Handles resolve dispute for the admin return controller workflow. */
    public function resolveDispute(ResolveDisputeRequest $request, Dispute $dispute, ResolveDispute $action): JsonResponse { $this->admin($request);$d=$request->validated();try{$row=$action->execute($dispute,$request->user(),$d['outcome'],$d['note']??null);}catch(ReturnException $e){return $this->error($e);}return response()->json(['data'=>['id'=>$row->public_id,'status'=>$row->status->value,'outcome'=>$row->outcome]]); }
    /** Handles row for the admin return controller workflow. */
    private function row(Request $request, ReturnRequest $row): array{$resource=(new ReturnRequestResource($row))->resolve($request);$resource['customer']=['id'=>$row->user?->id,'name'=>$row->user?->name,'email'=>$row->user?->email];return $resource;}
    /** Handles refund row for the admin return controller workflow. */
    private function refundRow(Refund $row):array{return ['id'=>$row->public_id,'status'=>$row->status->value,'amountMinor'=>(int)$row->amount_minor,'cashRefundMinor'=>(int)$row->cash_refund_minor,'coinRefundCoins'=>(int)$row->coin_refund_coins,'attemptCount'=>(int)$row->attempt_count,'manualReference'=>$row->manual_reference,'processedAt'=>$row->processed_at?->toIso8601String(),'events'=>$row->events->map(/** Inline callback for this operation. */ fn($e)=>['event'=>$e->event,'reference'=>$e->reference,'message'=>$e->message,'occurredAt'=>$e->occurred_at?->toIso8601String()])->values()];}
    /** Handles admin for the admin return controller workflow. */
    private function admin(Request $request):void{$r=$request->user()?->role;$v=$r instanceof UserRole?$r->value:(string)$r;abort_unless(in_array($v,[UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);}
    /** Handles error for the admin return controller workflow. */
    private function error(ReturnException $e):JsonResponse{return response()->json(['message'=>$e->getMessage(),'errors'=>[$e->field=>[$e->getMessage()]]],422);}
}
