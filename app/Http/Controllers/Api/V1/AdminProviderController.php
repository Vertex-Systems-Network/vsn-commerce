<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Providers\Services\ProviderReconciliationService;
use App\Domain\Providers\Services\ProviderRuntimeService;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ProviderReconciliationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
/** Defines the AdminProviderController class and its project responsibilities. */
final class AdminProviderController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request,ProviderRuntimeService $runtime):JsonResponse{$this->admin($request);return response()->json(['data'=>['providers'=>$runtime->latest()->map(/** Inline callback for this operation. */ fn($r)=>$runtime->row($r))->all(),'reconciliations'=>$this->runs()]]);}
    /** Handles probe for the admin provider controller workflow. */
    public function probe(Request $request,ProviderRuntimeService $runtime):JsonResponse{$this->admin($request);return response()->json(['data'=>['providers'=>$runtime->probeAll()]]);}
    /** Handles reconcile for the admin provider controller workflow. */
    public function reconcile(Request $request,ProviderReconciliationService $service):JsonResponse{$this->admin($request);$d=$request->validate(['type'=>['required',Rule::in(['payment','shipping','kyc'])],'code'=>'required|string|max:80','limit'=>'nullable|integer|min:1|max:500']);$run=$service->run($d['type'],$d['code'],(int)($d['limit']??200));return response()->json(['data'=>$this->run($run)]);}
    /** Handles runs for the admin provider controller workflow. */
    private function runs():array{return ProviderReconciliationRun::query()->latest('started_at')->limit(30)->get()->map(/** Inline callback for this operation. */ fn($r)=>$this->run($r))->all();}
    /** Executes the admin provider controller operation. */
    private function run(ProviderReconciliationRun $r):array{return ['id'=>$r->public_id,'type'=>$r->provider_type,'code'=>$r->provider_code,'status'=>$r->status,'checked'=>$r->checked_count,'matched'=>$r->matched_count,'mismatches'=>$r->mismatch_count,'errors'=>$r->error_count,'details'=>$r->details?:[],'startedAt'=>$r->started_at?->toIso8601String(),'completedAt'=>$r->completed_at?->toIso8601String()];}
    /** Handles admin for the admin provider controller workflow. */
    private function admin(Request $r):void{$role=$r->user()?->role;$v=$role instanceof UserRole?$role->value:(string)$role;abort_unless(in_array($v,[UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);}
}
