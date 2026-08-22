<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Services\PaymentLifecycleService;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentIntentResource;
use App\Models\PaymentIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the AdminPaymentController class and its project responsibilities. */
class AdminPaymentController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request):JsonResponse
    {
        $this->allow($request);$q=PaymentIntent::query()->with(['user','order','savedPaymentMethod'])->latest();
        if($request->filled('status'))$q->where('status',$request->string('status'));
        if($request->filled('provider'))$q->where('provider',$request->string('provider'));
        if($request->filled('q')){$term=$request->string('q');$q->where(/** Inline callback for this operation. */ fn($x)=>$x->where('public_id','like',"%{$term}%")->orWhere('provider_payment_id','like',"%{$term}%")->orWhereHas('user',/** Inline callback for this operation. */ fn($u)=>$u->where('email','like',"%{$term}%")));}
        $rows=$q->limit(200)->get();
        return response()->json(['data'=>$rows->map(/** Inline callback for this operation. */ fn($i)=>$this->row($i))->values()]);
    }
    /** Handles sync for the admin payment controller workflow. */
    public function sync(Request $request,PaymentIntent $paymentIntent,PaymentLifecycleService $life):JsonResponse
    {
        $this->allow($request);try{$intent=$life->sync($paymentIntent);return response()->json(['data'=>$this->row($intent)]);}catch(PaymentException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }
    /** Handles row for the admin payment controller workflow. */
    private function row(PaymentIntent $i):array{return ['id'=>$i->public_id,'user'=>['name'=>$i->user?->name,'email'=>$i->user?->email],'orderId'=>$i->order?->public_id,'provider'=>$i->provider,'providerPaymentId'=>$i->provider_payment_id,'paymentMethod'=>$i->payment_method,'status'=>$i->status->value,'providerStatus'=>$i->provider_status,'amountMinor'=>$i->amount_minor,'currency'=>$i->currency,'attempts'=>$i->initialization_attempts,'providerSyncedAt'=>$i->provider_synced_at?->toIso8601String(),'providerSyncError'=>$i->provider_sync_error,'createdAt'=>$i->created_at?->toIso8601String()];}
    /** Handles allow for the admin payment controller workflow. */
    private function allow(Request $r):void{$role=$r->user()?->role;$v=$role instanceof UserRole?$role->value:(string)$role;abort_unless(in_array($v,[UserRole::Finance->value,UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);}
}
