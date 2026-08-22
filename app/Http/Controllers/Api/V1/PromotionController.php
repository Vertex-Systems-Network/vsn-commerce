<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Promotions\Services\DealDiscoveryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the PromotionController class and its project responsibilities. */
class PromotionController extends Controller
{
    /** Handles deals for the promotion controller workflow. */
    public function deals(Request $request,DealDiscoveryService $service):JsonResponse{return response()->json(['data'=>$service->execute($request,min(48,max(1,(int)$request->integer('limit',24))))]);}
}
