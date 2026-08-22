<?php
namespace App\Http\Controllers\Api\V1;

use App\Domain\Operations\Services\GoLiveStabilizationService;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\GoLiveWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the AdminGoLiveController class and its project responsibilities. */
class AdminGoLiveController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request, GoLiveStabilizationService $service): JsonResponse
    {
        $this->allowed($request,[UserRole::Finance->value,UserRole::Admin->value,UserRole::SuperAdmin->value]);
        $latest=GoLiveWindow::query()->with(['observations'=>/** Inline callback for this operation. */ fn($q)=>$q->latest('sequence')->limit(20),'signoffs','incident','releaseCandidateManifest'])->latest('id')->first();
        $history=GoLiveWindow::query()->with(['signoffs','incident','releaseCandidateManifest'])->latest('id')->limit(20)->get()->map(/** Inline callback for this operation. */ fn($w)=>$service->windowRow($w))->all();
        return response()->json(['data'=>['current'=>$latest?$service->status($latest):$service->status(null),'history'=>$history]]);
    }

    /** Handles open for the admin go live controller workflow. */
    public function open(Request $request, GoLiveStabilizationService $service): JsonResponse
    {
        $this->allowed($request,[UserRole::Admin->value,UserRole::SuperAdmin->value]);
        $window=$service->open($request->user());
        return response()->json(['data'=>$service->status($window)],201);
    }

    /** Handles observe for the admin go live controller workflow. */
    public function observe(Request $request, GoLiveWindow $window, GoLiveStabilizationService $service): JsonResponse
    {
        $this->allowed($request,[UserRole::Admin->value,UserRole::SuperAdmin->value]);
        $observation=$service->observe($window,true);
        return response()->json(['data'=>['observation'=>$service->observationRow($observation),'current'=>$service->status($window->fresh())]]);
    }

    /** Handles signoff for the admin go live controller workflow. */
    public function signoff(Request $request, GoLiveWindow $window, GoLiveStabilizationService $service): JsonResponse
    {
        $data=$request->validate(['area'=>'required|string|max:32','decision'=>'required|in:approved,rejected','comment'=>'nullable|string|max:2000']);
        return response()->json(['data'=>$service->sign($window,$request->user(),$data['area'],$data['decision'],$data['comment']??null)]);
    }

    /** Handles rollback for the admin go live controller workflow. */
    public function rollback(Request $request, GoLiveWindow $window, GoLiveStabilizationService $service): JsonResponse
    {
        $this->allowed($request,[UserRole::Admin->value,UserRole::SuperAdmin->value]);
        $data=$request->validate(['targetRelease'=>'required|string|max:80','note'=>'required|string|max:3000']);
        return response()->json(['data'=>$service->rolledBack($window,$request->user(),$data['targetRelease'],$data['note'])]);
    }

    /** Handles allowed for the admin go live controller workflow. */
    private function allowed(Request $request,array $roles):void
    {
        $role=$request->user()?->role;$value=$role instanceof UserRole?$role->value:(string)$role;
        abort_unless(in_array($value,$roles,true),403);
    }
}
