<?php
namespace App\Http\Controllers\Api\V1;
use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\ReviewHelpfulVote;
use App\Models\ReviewReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the ReviewEngagementController class and its project responsibilities. */
class ReviewEngagementController extends Controller
{
    /** Handles helpful for the review engagement controller workflow. */
    public function helpful(Request $request,Review $review):JsonResponse
    {
        abort_unless($review->status===ReviewStatus::Approved,404);abort_if($review->user_id===$request->user()->id,422,'You cannot vote on your own review.');
        $active=DB::transaction(/** Inline callback for this operation. */ function()use($request,$review){$existing=ReviewHelpfulVote::query()->where('review_id',$review->id)->where('user_id',$request->user()->id)->lockForUpdate()->first();if($existing){$existing->delete();$active=false;}else{ReviewHelpfulVote::create(['review_id'=>$review->id,'user_id'=>$request->user()->id,'created_at'=>now()]);$active=true;}$count=ReviewHelpfulVote::query()->where('review_id',$review->id)->count();$review->update(['helpful_count'=>$count]);return $active;},3);
        return response()->json(['data'=>['helpful'=>$active,'helpfulCount'=>$review->fresh()->helpful_count]]);
    }
    /** Handles report for the review engagement controller workflow. */
    public function report(Request $request,Review $review):JsonResponse
    {
        abort_unless($review->status===ReviewStatus::Approved,404);abort_if($review->user_id===$request->user()->id,422,'You cannot report your own review.');
        $d=$request->validate(['reason'=>'required|in:spam,abuse,misleading,privacy,other','details'=>'nullable|string|max:1500']);
        $report=DB::transaction(/** Inline callback for this operation. */ function()use($request,$review,$d){$row=ReviewReport::query()->where('review_id',$review->id)->where('user_id',$request->user()->id)->lockForUpdate()->first();if($row){$row->update(['reason'=>$d['reason'],'details'=>$d['details']??null,'status'=>'pending','resolution_note'=>null,'resolved_by'=>null,'resolved_at'=>null]);}else{$row=ReviewReport::create(['public_id'=>(string)Str::ulid(),'review_id'=>$review->id,'user_id'=>$request->user()->id,'reason'=>$d['reason'],'details'=>$d['details']??null,'status'=>'pending']);}$review->update(['report_count'=>ReviewReport::query()->where('review_id',$review->id)->where('status','pending')->count()]);return $row;},3);
        return response()->json(['data'=>['id'=>$report->public_id,'status'=>$report->status]],201);
    }
}
