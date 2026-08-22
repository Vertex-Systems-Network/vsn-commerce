<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Notifications\Services\NotificationPreferenceService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MarketplaceNotificationResource;
use App\Models\MarketplaceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the NotificationController class and its project responsibilities. */
class NotificationController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request):JsonResponse
    {
        $query=MarketplaceNotification::query()->where('user_id',$request->user()->id)->where('in_app_visible',true)->latest();if($request->boolean('unread'))$query->whereNull('read_at');
        if($category=$request->string('category')->toString())$query->where('category',$category);
        $rows=$query->paginate(min(100,max(1,(int)$request->input('perPage',30))));
        return response()->json(['data'=>['items'=>MarketplaceNotificationResource::collection($rows->getCollection())->resolve($request),'meta'=>['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage(),'unreadCount'=>MarketplaceNotification::query()->where('user_id',$request->user()->id)->where('in_app_visible',true)->whereNull('read_at')->count()]]]);
    }
    /** Handles read for the notification controller workflow. */
    public function read(Request $request,MarketplaceNotification $notification):MarketplaceNotificationResource{abort_unless($notification->user_id===$request->user()->id,404);if(!$notification->read_at)$notification->update(['read_at'=>now()]);return new MarketplaceNotificationResource($notification->fresh());}
    /** Handles read all for the notification controller workflow. */
    public function readAll(Request $request):JsonResponse{$count=MarketplaceNotification::query()->where('user_id',$request->user()->id)->where('in_app_visible',true)->whereNull('read_at')->update(['read_at'=>now()]);return response()->json(['data'=>['updated'=>$count,'unreadCount'=>0]]);}
    /** Handles preferences for the notification controller workflow. */
    public function preferences(Request $request,NotificationPreferenceService $service):JsonResponse{return response()->json(['data'=>['preferences'=>$service->matrix($request->user()),'categories'=>NotificationPreferenceService::CATEGORIES,'channels'=>NotificationPreferenceService::CHANNELS]]);}
    /** Handles update preferences for the notification controller workflow. */
    public function updatePreferences(Request $request,NotificationPreferenceService $service):JsonResponse{$data=$request->validate(['preferences'=>'required|array']);return response()->json(['data'=>['preferences'=>$service->update($request->user(),$data['preferences'])]]);}
}
