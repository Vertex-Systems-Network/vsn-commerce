<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Operations\Services\OperationalHealthService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
/** Defines the HealthController class and its project responsibilities. */
class HealthController extends Controller
{
    /** Handles live for the health controller workflow. */
    public function live():JsonResponse{return response()->json(['data'=>['service'=>'vsn-ecommerce-api','status'=>'ok','version'=>(string)config('vsn.operations.release','unknown')]]);}
    /** Handles ready for the health controller workflow. */
    public function ready(OperationalHealthService $health):JsonResponse{$snapshot=$health->snapshot(false);return response()->json(['data'=>$snapshot],$snapshot['status']==='ready'?200:503);}
}
