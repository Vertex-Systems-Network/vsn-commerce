<?php
namespace App\Events;
use App\Models\MarketplaceNotification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
/** Defines the MarketplaceNotificationCreated class and its project responsibilities. */
class MarketplaceNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable,SerializesModels;
    /** Initializes the MarketplaceNotificationCreated instance and its dependencies. */
    public function __construct(public MarketplaceNotification $notification){}
    /** Handles broadcast on for the marketplace notification created workflow. */
    public function broadcastOn():array{return [new PrivateChannel('user.'.$this->notification->user_id)];}
    /** Handles broadcast as for the marketplace notification created workflow. */
    public function broadcastAs():string{return 'notification.created';}
    /** Handles broadcast with for the marketplace notification created workflow. */
    public function broadcastWith():array{return ['id'=>$this->notification->public_id,'category'=>$this->notification->category,'type'=>$this->notification->type,'title'=>$this->notification->title,'body'=>$this->notification->body,'actionUrl'=>$this->notification->action_url,'createdAt'=>$this->notification->created_at?->toISOString()];}
}
