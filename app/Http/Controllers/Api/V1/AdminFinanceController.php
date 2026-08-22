<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Finance\Actions\ConfirmCodCollection;
use App\Domain\Finance\Actions\CreateVendorPayoutBatch;
use App\Domain\Finance\Actions\ReconcilePayoutBatch;
use App\Domain\Finance\Actions\MarkVendorPayoutPaid;
use App\Domain\Finance\Actions\ManageVendorPayoutAttempt;
use App\Domain\Finance\Actions\ReconcileVendorSettlements;
use App\Domain\Finance\Actions\ReviewVendorPayout;
use App\Domain\Finance\Actions\RunFinanceReconciliation;
use App\Domain\Finance\Services\FinanceDashboardService;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\VendorPayout;
use App\Models\VendorPayoutBatch;
use App\Models\VendorPayoutMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the AdminFinanceController class and its project responsibilities. */
class AdminFinanceController extends Controller
{
    /** Handles dashboard for the admin finance controller workflow. */
    public function dashboard(Request $request,FinanceDashboardService $service):JsonResponse{$this->finance($request);return response()->json(['data'=>$service->summary()]);}
    /** Handles payouts for the admin finance controller workflow. */
    public function payouts(Request $request):JsonResponse{$this->finance($request);$rows=VendorPayout::query()->with(['vendor','requestedBy','approvedBy','items.settlement','batch','attempts'])->latest()->limit(200)->get();return response()->json(['data'=>$rows->map(/** Inline callback for this operation. */ fn($p)=>['id'=>$p->public_id,'vendor'=>$p->vendor?->name,'status'=>$p->status->value,'amountMinor'=>$p->amount_minor,'currency'=>$p->currency,'requestedBy'=>$p->requestedBy?->name,'approvedBy'=>$p->approvedBy?->name,'providerReference'=>$p->provider_reference,'retryCount'=>(int)($p->retry_count??0),'failureCode'=>$p->failure_code,'failureMessage'=>$p->failure_message,'failedAt'=>$p->failed_at?->toIso8601String(),'payoutMethod'=>$p->payout_method_snapshot,'attempts'=>$p->attempts?->map(/** Inline callback for this operation. */ fn($a)=>['id'=>$a->public_id,'attemptNo'=>$a->attempt_no,'status'=>$a->status,'provider'=>$a->provider,'providerReference'=>$a->provider_reference,'failureCode'=>$a->failure_code,'failureMessage'=>$a->failure_message,'startedAt'=>$a->started_at?->toIso8601String(),'completedAt'=>$a->completed_at?->toIso8601String()])->all()??[],'createdAt'=>$p->created_at?->toIso8601String(),'paidAt'=>$p->paid_at?->toIso8601String(),'batchId'=>$p->batch?->public_id])->all()]);}
    /** Handles review payout for the admin finance controller workflow. */
    public function reviewPayout(Request $request,VendorPayout $payout,ReviewVendorPayout $action):JsonResponse{$this->finance($request);$d=$request->validate(['approve'=>'required|boolean','note'=>'nullable|string|max:1000']);$p=$action->execute($payout,$request->user(),(bool)$d['approve'],$d['note']??null);return response()->json(['data'=>['id'=>$p->public_id,'status'=>$p->status->value]]);}
    /** Handles mark paid for the admin finance controller workflow. */
    public function markPaid(Request $request,VendorPayout $payout,MarkVendorPayoutPaid $action):JsonResponse{$this->finance($request);$d=$request->validate(['providerReference'=>'required|string|max:190']);$p=$action->execute($payout,$d['providerReference'],$request->user());return response()->json(['data'=>['id'=>$p->public_id,'status'=>$p->status->value,'paidAt'=>$p->paid_at?->toIso8601String(),'batchId'=>$p->batch?->public_id]]);}
    /** Handles cancel payout for the admin finance controller workflow. */
    public function cancelPayout(Request $request,VendorPayout $payout,ReviewVendorPayout $action):JsonResponse{$this->finance($request);$d=$request->validate(['note'=>'nullable|string|max:1000']);$p=$action->cancel($payout,$request->user(),$d['note']??null);return response()->json(['data'=>['id'=>$p->public_id,'status'=>$p->status->value]]);}
    /** Handles fail payout for the admin finance controller workflow. */
    public function failPayout(Request $request,VendorPayout $payout,ManageVendorPayoutAttempt $action):JsonResponse{$this->finance($request);$d=$request->validate(['code'=>'required|string|max:100','message'=>'required|string|max:1000','providerReference'=>'nullable|string|max:190']);$p=$action->fail($payout,$request->user(),$d['code'],$d['message'],$d['providerReference']??null);return response()->json(['data'=>['id'=>$p->public_id,'status'=>$p->status->value,'retryCount'=>$p->retry_count,'failureCode'=>$p->failure_code,'failureMessage'=>$p->failure_message]]);}
    /** Handles retry payout for the admin finance controller workflow. */
    public function retryPayout(Request $request,VendorPayout $payout,ManageVendorPayoutAttempt $action):JsonResponse{$this->finance($request);$a=$action->retry($payout,$request->user());return response()->json(['data'=>['id'=>$payout->public_id,'status'=>'processing','attemptId'=>$a->public_id,'attemptNo'=>$a->attempt_no,'idempotencyKey'=>$a->idempotency_key]]);}
    /** Handles verify payout method for the admin finance controller workflow. */
    public function verifyPayoutMethod(Request $request,VendorPayoutMethod $payoutMethod):JsonResponse{$this->finance($request);$d=$request->validate(['verified'=>'required|boolean','note'=>'nullable|string|max:1000']);abort_if($payoutMethod->revoked_at,422,'Revoked payout methods cannot be verified.');$payoutMethod->update(['verified_at'=>$d['verified']?now():null,'verified_by_user_id'=>$d['verified']?$request->user()->id:null,'metadata'=>array_merge($payoutMethod->metadata??[],['verification_note'=>$d['note']??null,'verified_by'=>$request->user()->id])]);return response()->json(['data'=>['id'=>$payoutMethod->public_id,'verified'=>(bool)$payoutMethod->verified_at,'verifiedAt'=>$payoutMethod->verified_at?->toIso8601String()]]);}
    /** Handles payout methods for the admin finance controller workflow. */
    public function payoutMethods(Request $request):JsonResponse{$this->finance($request);$rows=VendorPayoutMethod::query()->with('vendor:id,name')->latest()->limit(200)->get();return response()->json(['data'=>$rows->map(/** Inline callback for this operation. */ fn($m)=>['id'=>$m->public_id,'vendor'=>$m->vendor?->name,'type'=>$m->type,'label'=>$m->label,'accountHolderName'=>$m->account_holder_name,'bankName'=>$m->bank_name,'accountLast4'=>$m->account_last4,'routingLast4'=>$m->routing_last4,'countryCode'=>$m->country_code,'currency'=>$m->currency,'isDefault'=>(bool)$m->is_default,'verified'=>(bool)$m->verified_at,'revoked'=>(bool)$m->revoked_at,'createdAt'=>$m->created_at?->toIso8601String()])->all()]);}
    /** Handles batches for the admin finance controller workflow. */
    public function batches(Request $request):JsonResponse{$this->finance($request);$rows=VendorPayoutBatch::query()->with('payouts.vendor')->latest()->limit(100)->get();return response()->json(['data'=>$rows->map(/** Inline callback for this operation. */ fn($b)=>['id'=>$b->public_id,'status'=>$b->status,'currency'=>$b->currency,'totalMinor'=>$b->total_minor,'payoutCount'=>$b->payout_count,'providerBatchReference'=>$b->provider_batch_reference,'createdAt'=>$b->created_at?->toIso8601String(),'completedAt'=>$b->completed_at?->toIso8601String(),'payouts'=>$b->payouts->map(/** Inline callback for this operation. */ fn($p)=>['id'=>$p->public_id,'vendor'=>$p->vendor?->name,'status'=>$p->status->value,'amountMinor'=>$p->amount_minor,'providerReference'=>$p->provider_reference])->all()])->all()]);}
    /** Handles create batch for the admin finance controller workflow. */
    public function createBatch(Request $request,CreateVendorPayoutBatch $action):JsonResponse{$this->finance($request);$d=$request->validate(['payoutIds'=>'required|array|min:1','payoutIds.*'=>'required|string','providerBatchReference'=>'nullable|string|max:190']);$b=$action->execute($request->user(),$d['payoutIds'],$d['providerBatchReference']??null);return response()->json(['data'=>['id'=>$b->public_id,'status'=>$b->status,'totalMinor'=>$b->total_minor,'payoutCount'=>$b->payout_count]]);}
    /** Handles confirm cod for the admin finance controller workflow. */
    public function confirmCod(Request $request,Order $order,ConfirmCodCollection $action):JsonResponse{$this->finance($request);$o=$action->execute($order);return response()->json(['data'=>['id'=>$o->public_id,'paymentStatus'=>$o->payment_status->value]]);}
    /** Handles mark delivered for the admin finance controller workflow. */
    public function markDelivered(Request $request,Order $order,ReconcileVendorSettlements $reconcile):JsonResponse{$this->finance($request);if(!$order->delivered_at)$order->update(['status'=>OrderStatus::Delivered,'delivered_at'=>now()]);$order->vendorOrders()->update(['status'=>OrderStatus::Delivered->value]);foreach($order->vendorOrders()->pluck('vendor_id')->filter()->unique() as $vendorId)$reconcile->execute((int)$vendorId);return response()->json(['data'=>['id'=>$order->public_id,'status'=>$order->fresh()->status->value,'deliveredAt'=>$order->fresh()->delivered_at?->toIso8601String()]]);}
    /** Handles reconcile for the admin finance controller workflow. */
    public function reconcile(Request $request,RunFinanceReconciliation $action):JsonResponse{$this->finance($request);$run=$action->execute($request->user(),true);return response()->json(['data'=>['id'=>$run->public_id,'status'=>$run->status,'issuesCount'=>$run->issues_count,'summary'=>$run->summary,'issues'=>$run->issues->map(/** Inline callback for this operation. */ fn($i)=>['code'=>$i->code,'referenceType'=>$i->reference_type,'referenceId'=>$i->reference_id,'expectedMinor'=>$i->expected_minor,'actualMinor'=>$i->actual_minor,'deltaMinor'=>$i->delta_minor,'message'=>$i->message])->all()]]);}
    /** Handles finance for the admin finance controller workflow. */
    private function finance(Request $request):void{$role=$request->user()?->role;$value=$role instanceof UserRole?$role->value:(string)$role;abort_unless(in_array($value,[UserRole::Finance->value,UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);}
}
