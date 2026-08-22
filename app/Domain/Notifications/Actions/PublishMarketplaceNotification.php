<?php
namespace App\Domain\Notifications\Actions;
use App\Domain\Notifications\Services\NotificationPreferenceService;
use App\Events\MarketplaceNotificationCreated;
use App\Models\MarketplaceNotification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the PublishMarketplaceNotification class and its project responsibilities. */
class PublishMarketplaceNotification
{
    /** Initializes the PublishMarketplaceNotification instance and its dependencies. */
    public function __construct(private readonly NotificationPreferenceService $preferences){}
    /** Executes the publish marketplace notification operation. */
    public function execute(User $user,string $category,string $type,string $title,string $body,string $dedupKey,?string $actionUrl=null,?string $referenceType=null,?string $referenceId=null,array $data=[],bool $critical=false):?MarketplaceNotification
    {
        $enabled=/** Inline callback for this operation. */ fn(string $channel):bool=>$critical&&in_array($channel,['in_app','email'],true)?true:$this->preferences->enabled($user,$category,$channel);
        if(!$enabled('in_app')&&!collect(['email','sms','push'])->contains(/** Inline callback for this operation. */ fn($c)=>$enabled($c)))return null;
        $notification=DB::transaction(/** Inline callback for this operation. */ function()use($user,$category,$type,$title,$body,$dedupKey,$actionUrl,$referenceType,$referenceId,$data,$enabled){
            $fullKey=hash('sha256',"{$user->id}|{$dedupKey}");
            $row=MarketplaceNotification::query()->createOrFirst(
                ['dedup_key'=>$fullKey],
                ['public_id'=>(string)Str::uuid(),'user_id'=>$user->id,'category'=>$category,'type'=>$type,'title'=>$title,'body'=>$body,'action_url'=>$actionUrl,'reference_type'=>$referenceType,'reference_id'=>$referenceId,'data'=>$data,'in_app_visible'=>$enabled('in_app')]
            );
            if($row->wasRecentlyCreated)foreach(['email','sms','push'] as $channel)if($enabled($channel))NotificationDelivery::query()->firstOrCreate(['marketplace_notification_id'=>$row->id,'channel'=>$channel],['status'=>'pending','available_at'=>now()]);
            return $row;
        },3);
        if($notification->wasRecentlyCreated && $notification->in_app_visible) event(new MarketplaceNotificationCreated($notification));
        return $notification;
    }
}
