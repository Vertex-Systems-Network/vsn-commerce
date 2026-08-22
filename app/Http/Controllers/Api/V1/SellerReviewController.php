<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Finance\Services\VendorResolver;
use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the SellerReviewController class and its project responsibilities. */
class SellerReviewController extends Controller
{
    /** Initializes the SellerReviewController instance and its dependencies. */
    public function __construct(private readonly VendorResolver $vendors){}
    /** Handles the index request for this resource. */
    public function index(Request $request):JsonResponse
    {
        $vendor=$this->vendors->forUser($request->user());
        $rows=Review::query()->whereHas('product',/** Inline callback for this operation. */ fn($q)=>$q->where('vendor_id',$vendor->id))->where('status',ReviewStatus::Approved->value)->with(['user','product.images','images'])->latest('submitted_at')->paginate(40);
        return response()->json(['data'=>['items'=>ReviewResource::collection($rows->getCollection())->resolve($request),'meta'=>['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage()]]]);
    }
    /** Handles reply for the seller review controller workflow. */
    public function reply(Request $request,Review $review):ReviewResource
    {
        $vendor=$this->vendors->forUser($request->user());$review->loadMissing('product');abort_unless($review->product?->vendor_id===$vendor->id,404);abort_unless($review->status===ReviewStatus::Approved,422,'Only approved reviews can receive a seller reply.');
        $d=$request->validate(['reply'=>'required|string|min:2|max:2000']);$reply=trim($d['reply']);$metadata=$review->metadata??[];$history=$metadata['seller_reply_history']??[];$history[]=['user_id'=>$request->user()->id,'reply'=>$reply,'at'=>now()->toIso8601String()];$metadata['seller_reply_history']=array_slice($history,-20);$review->update(['seller_reply'=>$reply,'seller_replied_by'=>$request->user()->id,'seller_replied_at'=>now(),'metadata'=>$metadata]);
        return new ReviewResource($review->fresh()->load(['user','product.images','images','sellerReplier']));
    }
}
