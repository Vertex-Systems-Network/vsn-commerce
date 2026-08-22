<?php
namespace App\Http\Controllers\Api\V1;

use App\Domain\Affiliate\Actions\AttachReferrer;
use App\Domain\Affiliate\Actions\EnrollAffiliate;
use App\Domain\Affiliate\Exceptions\AffiliateException;
use App\Domain\Affiliate\Services\AffiliateDashboardService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Affiliate\AttachReferrerRequest;
use App\Http\Requests\Affiliate\EnrollAffiliateRequest;
use App\Http\Resources\AffiliateCommissionResource;
use App\Models\AffiliateCommission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the AffiliateController class and its project responsibilities. */
class AffiliateController extends Controller
{
    /** Handles the show request for this resource. */
    public function show(Request $request, AffiliateDashboardService $dashboard): JsonResponse
    {
        return response()->json(['data'=>$dashboard->summary($request->user())]);
    }

    /** Handles enroll for the affiliate controller workflow. */
    public function enroll(EnrollAffiliateRequest $request, EnrollAffiliate $action, AffiliateDashboardService $dashboard): JsonResponse
    {
        $action->execute($request->user(), (string) config('vsn.affiliate.terms_version','2026-08'), ['ip_hash'=>hash('sha256',(string)$request->ip()),'user_agent_hash'=>hash('sha256',(string)$request->userAgent())]);
        return response()->json(['data'=>$dashboard->summary($request->user())], 201);
    }

    /** Handles attach referrer for the affiliate controller workflow. */
    public function attachReferrer(AttachReferrerRequest $request, AttachReferrer $action, AffiliateDashboardService $dashboard): JsonResponse
    {
        try { $action->execute($request->user(), $request->validated('referralCode')); }
        catch (AffiliateException $e) { return response()->json(['message'=>$e->getMessage(),'errors'=>[$e->field=>[$e->getMessage()]]],422); }
        return response()->json(['data'=>$dashboard->summary($request->user())]);
    }

    /** Handles commissions for the affiliate controller workflow. */
    public function commissions(Request $request): JsonResponse
    {
        $rows = AffiliateCommission::query()->where('beneficiary_id',$request->user()->id)->with('order')->latest('id')->paginate(30);
        return response()->json(['data'=>AffiliateCommissionResource::collection($rows->getCollection())->resolve($request),'meta'=>['currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }
}
