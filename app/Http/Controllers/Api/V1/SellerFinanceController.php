<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Finance\Actions\RequestVendorPayout;
use App\Domain\Finance\Services\VendorFinanceService;
use App\Domain\Finance\Services\VendorResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the SellerFinanceController class and its project responsibilities. */
class SellerFinanceController extends Controller
{
    /** Initializes the SellerFinanceController instance and its dependencies. */
    public function __construct(private readonly VendorResolver $vendors){}
    /** Handles the show request for this resource. */
    public function show(Request $request,VendorFinanceService $service):JsonResponse{$vendor=$this->vendors->forUser($request->user());return response()->json(['data'=>$service->summary($vendor)]);}
    /** Handles payouts for the seller finance controller workflow. */
    public function payouts(Request $request):JsonResponse{$vendor=$this->vendors->forUser($request->user());$rows=$vendor->payouts()->with(['items.settlement','attempts'])->latest()->limit(100)->get();return response()->json(['data'=>$rows->map(/** Inline callback for this operation. */ fn($p)=>$this->payout($p))->all()]);}
    /** Handles request payout for the seller finance controller workflow. */
    public function requestPayout(Request $request,RequestVendorPayout $action):JsonResponse{$d=$request->validate(['idempotencyKey'=>'required|string|max:190','amountMinor'=>'nullable|integer|min:1']);$vendor=$this->vendors->forUser($request->user());$p=$action->execute($request->user(),$vendor,$d['idempotencyKey'],isset($d['amountMinor'])?(int)$d['amountMinor']:null);return response()->json(['data'=>$this->payout($p)]);}
    /** Handles payout for the seller finance controller workflow. */
    private function payout($p):array{return ['id'=>$p->public_id,'status'=>$p->status->value,'currency'=>$p->currency,'amountMinor'=>$p->amount_minor,'providerReference'=>$p->provider_reference,'retryCount'=>(int)($p->retry_count??0),'failureCode'=>$p->failure_code,'failureMessage'=>$p->failure_message,'failedAt'=>$p->failed_at?->toIso8601String(),'payoutMethod'=>$p->payout_method_snapshot,'requestedAt'=>$p->created_at?->toIso8601String(),'approvedAt'=>$p->approved_at?->toIso8601String(),'paidAt'=>$p->paid_at?->toIso8601String(),'attempts'=>$p->attempts?->map(/** Inline callback for this operation. */ fn($a)=>['id'=>$a->public_id,'attemptNo'=>$a->attempt_no,'status'=>$a->status,'provider'=>$a->provider,'providerReference'=>$a->provider_reference,'failureCode'=>$a->failure_code,'failureMessage'=>$a->failure_message,'startedAt'=>$a->started_at?->toIso8601String(),'completedAt'=>$a->completed_at?->toIso8601String()])->all()??[],'items'=>$p->items->map(/** Inline callback for this operation. */ fn($i)=>['settlementId'=>$i->settlement?->public_id,'amountMinor'=>$i->amount_minor])->all()];}
}
