<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Domain\Messaging\Services\UnreadMessageCounter;
use App\Models\MarketplaceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the ActivityController class and its project responsibilities. */
class ActivityController extends Controller
{
    /** Handles the show request for this resource. */
    public function show(Request $request,UnreadMessageCounter $counter):JsonResponse
    {
        $id=$request->user()->id;$notifications=MarketplaceNotification::query()->where('user_id',$id)->where('in_app_visible',true)->whereNull('read_at')->count();
        $messages=$counter->forUser($request->user());
        return response()->json(['data'=>['notificationsUnread'=>$notifications,'messagesUnread'=>$messages]]);
    }
}
