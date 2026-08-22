<?php
namespace App\Events;
use App\Models\ConversationMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
/** Defines the ConversationMessageCreated class and its project responsibilities. */
class ConversationMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable,SerializesModels;
    /** Initializes the ConversationMessageCreated instance and its dependencies. */
    public function __construct(public ConversationMessage $message){}
    /** Handles broadcast on for the conversation message created workflow. */
    public function broadcastOn():array{$channels=[new PrivateChannel('conversation.'.$this->message->conversation->public_id)];if($this->message->conversation->kind==='support')$channels[]=new PrivateChannel('support.inbox');return $channels;}
    /** Handles broadcast as for the conversation message created workflow. */
    public function broadcastAs():string{return 'message.created';}
    /** Handles broadcast with for the conversation message created workflow. */
    public function broadcastWith():array{return ['id'=>$this->message->public_id,'conversationId'=>$this->message->conversation->public_id,'sender'=>['id'=>$this->message->sender_user_id,'name'=>$this->message->sender?->name],'body'=>$this->message->body,'attachments'=>$this->message->attachments->map(/** Inline callback for this operation. */ fn($a)=>['id'=>$a->public_id,'name'=>$a->original_name,'mimeType'=>$a->mime_type,'sizeBytes'=>$a->size_bytes])->values()->all(),'createdAt'=>$this->message->created_at?->toISOString()];}
}
