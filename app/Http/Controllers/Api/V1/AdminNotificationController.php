<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\Actions\PublishMarketplaceNotification;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceNotification;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Defines the AdminNotificationController class and its project responsibilities. */
class AdminNotificationController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        $this->admin($request);
        $rows=MarketplaceNotification::query()->where('type','admin.broadcast')->with('user:id,name,email,role')->latest()->limit(100)->get();
        return response()->json(['data'=>$rows->map(/** Inline callback for this operation. */ fn($n)=>[
            'id'=>$n->public_id,'recipient'=>['name'=>$n->user?->name,'email'=>$n->user?->email,'role'=>$n->user?->role?->value],
            'title'=>$n->title,'body'=>$n->body,'actionUrl'=>$n->action_url,'createdAt'=>$n->created_at?->toISOString(),
            'campaignId'=>$n->data['campaignId']??null,
        ])->values()]);
    }

    /** Handles broadcast for the admin notification controller workflow. */
    public function broadcast(Request $request, PublishMarketplaceNotification $publish): JsonResponse
    {
        $this->admin($request);
        $data=$request->validate([
            'audience'=>['required','in:all,customers,sellers,staff'],
            'title'=>['required','string','max:120'],
            'body'=>['required','string','max:1000'],
            'actionUrl'=>['nullable','string','max:500'],
        ]);
        $roles=match($data['audience']){
            'customers'=>[UserRole::Customer->value],
            'sellers'=>[UserRole::Seller->value,UserRole::SellerStaff->value],
            'staff'=>[UserRole::Support->value,UserRole::Finance->value,UserRole::Moderator->value,UserRole::Admin->value,UserRole::SuperAdmin->value],
            default=>array_map(/** Inline callback for this operation. */ fn(UserRole $role)=>$role->value,UserRole::cases()),
        };
        $campaign=(string)Str::uuid(); $count=0;
        User::query()->whereIn('role',$roles)->orderBy('id')->chunkById(250,/** Inline callback for this operation. */ function($users) use(&$count,$publish,$data,$campaign): void {
            foreach($users as $user){
                $created=$publish->execute($user,'account','admin.broadcast',$data['title'],$data['body'],"admin-broadcast:{$campaign}:{$user->id}",$data['actionUrl']??null,'admin_broadcast',$campaign,['campaignId'=>$campaign,'audience'=>$data['audience']]);
                if($created)$count++;
            }
        });
        return response()->json(['data'=>['campaignId'=>$campaign,'recipients'=>$count,'audience'=>$data['audience']]]);
    }


    /** Handles deliveries for the admin notification controller workflow. */
    public function deliveries(Request $request): JsonResponse
    {
        $this->admin($request);$status=$request->string('status')->toString();
        $query=NotificationDelivery::query()->with(['notification.user:id,name,email,role','deliveryAttempts'])->latest('id');if($status)$query->where('status',$status);
        $rows=$query->limit(250)->get()->map(/** Inline callback for this operation. */ fn($d)=>['id'=>$d->id,'channel'=>$d->channel,'status'=>$d->status,'attempts'=>(int)$d->attempts,'availableAt'=>$d->available_at?->toISOString(),'sentAt'=>$d->sent_at?->toISOString(),'lastError'=>$d->last_error,'recipient'=>['name'=>$d->notification?->user?->name,'email'=>$d->notification?->user?->email,'role'=>$d->notification?->user?->role?->value],'notification'=>['id'=>$d->notification?->public_id,'title'=>$d->notification?->title,'type'=>$d->notification?->type],'attemptLog'=>$d->deliveryAttempts->map(/** Inline callback for this operation. */ fn($a)=>['number'=>$a->attempt_number,'status'=>$a->status,'provider'=>$a->provider,'error'=>$a->error,'startedAt'=>$a->started_at?->toISOString(),'finishedAt'=>$a->finished_at?->toISOString()])->all()]);
        return response()->json(['data'=>$rows]);
    }

    /** Handles retry delivery for the admin notification controller workflow. */
    public function retryDelivery(Request $request,NotificationDelivery $delivery): JsonResponse
    {
        $this->admin($request);abort_unless(in_array($delivery->status,['failed','disabled'],true),409,'Only failed or disabled deliveries can be retried.');
        $delivery->forceFill(['status'=>'pending','available_at'=>now(),'last_error'=>null])->save();
        return response()->json(['data'=>['id'=>$delivery->id,'status'=>'pending']]);
    }

    /** Handles campaign summary for the admin notification controller workflow. */
    public function campaignSummary(Request $request): JsonResponse
    {
        $this->admin($request);$rows=MarketplaceNotification::query()->where('type','admin.broadcast')->latest()->limit(500)->with('deliveries')->get()->groupBy(/** Inline callback for this operation. */ fn($n)=>$n->data['campaignId']??'unknown')->map(/** Inline callback for this operation. */ function($group,$campaign){$deliveries=$group->flatMap->deliveries;return ['campaignId'=>$campaign,'recipients'=>$group->count(),'title'=>$group->first()?->title,'createdAt'=>$group->max('created_at')?->toISOString(),'delivery'=>['pending'=>$deliveries->where('status','pending')->count(),'processing'=>$deliveries->where('status','processing')->count(),'sent'=>$deliveries->where('status','sent')->count(),'failed'=>$deliveries->where('status','failed')->count(),'disabled'=>$deliveries->where('status','disabled')->count()]];})->values();
        return response()->json(['data'=>$rows]);
    }

    /** Handles admin for the admin notification controller workflow. */
    private function admin(Request $request): void
    {
        $role=$request->user()?->role; $value=$role instanceof UserRole?$role->value:(string)$role;
        abort_unless(in_array($value,[UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);
    }
}
