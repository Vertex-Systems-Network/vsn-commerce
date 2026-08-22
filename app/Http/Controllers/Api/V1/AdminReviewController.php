<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reviews\Actions\ModerateReview;
use App\Domain\Reviews\Exceptions\ReviewException;
use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\ModerateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Models\ReviewReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the AdminReviewController class and its project responsibilities. */
class AdminReviewController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        $this->viewer($request);
        $status = $request->string('status')->toString() ?: ReviewStatus::Pending->value;
        $rows = Review::query()->where('status',$status)->with(['user','order','orderItem','product.images','images','rewardCoupon.review.product','rewardCoupon.review.orderItem'])->latest('submitted_at')->paginate(50);
        return response()->json(['data'=>['items'=>ReviewResource::collection($rows->getCollection())->resolve($request),'meta'=>['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage()]]]);
    }

    /** Handles moderate for the admin review controller workflow. */
    public function moderate(ModerateReviewRequest $request, Review $review, ModerateReview $action): ReviewResource|JsonResponse
    {
        $this->moderator($request);
        $data=$request->validated();
        try { $row=$action->execute($review,$request->user(),ReviewStatus::from($data['status']),$data['note']??null); }
        catch(ReviewException $exception){ return response()->json(['message'=>$exception->getMessage(),'errors'=>[$exception->field=>[$exception->getMessage()]]],422); }
        return new ReviewResource($row);
    }


    /** Handles reports for the admin review controller workflow. */
    public function reports(Request $request): JsonResponse
    {
        $this->viewer($request);$status=$request->string('status')->toString()?:'pending';
        $rows=ReviewReport::query()->where('status',$status)->with(['user','review.user','review.product.images'])->latest()->paginate(50);
        return response()->json(['data'=>['items'=>$rows->getCollection()->map(/** Inline callback for this operation. */ fn($r)=>['id'=>$r->public_id,'reason'=>$r->reason,'details'=>$r->details,'status'=>$r->status,'createdAt'=>$r->created_at?->toISOString(),'reporter'=>['name'=>$r->user?->name],'review'=>(new ReviewResource($r->review))->resolve($request)])->values(),'meta'=>['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage()]]]);
    }

    /** Handles resolve report for the admin review controller workflow. */
    public function resolveReport(Request $request, ReviewReport $report): JsonResponse
    {
        $this->moderator($request);abort_unless($report->status==='pending',422,'Only pending reports can be resolved.');$d=$request->validate(['status'=>'required|in:resolved,dismissed','note'=>'nullable|string|max:2000']);
        $report->update(['status'=>$d['status'],'resolution_note'=>$d['note']??null,'resolved_by'=>$request->user()->id,'resolved_at'=>now()]);
        $review=$report->review;$review->update(['report_count'=>ReviewReport::query()->where('review_id',$review->id)->where('status','pending')->count()]);
        return response()->json(['data'=>['id'=>$report->public_id,'status'=>$report->status]]);
    }

    /** Handles viewer for the admin review controller workflow. */
    private function viewer(Request $request): void
    {
        $role=$request->user()?->role; $value=$role instanceof UserRole?$role->value:(string)$role;
        abort_unless(in_array($value,[UserRole::Support->value,UserRole::Moderator->value,UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);
    }
    /** Handles moderator for the admin review controller workflow. */
    private function moderator(Request $request): void
    {
        $role=$request->user()?->role; $value=$role instanceof UserRole?$role->value:(string)$role;
        abort_unless(in_array($value,[UserRole::Moderator->value,UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);
    }
}
