<?php
namespace App\Http\Resources;
use App\Models\ConversationParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
/** Defines the ConversationResource class and its project responsibilities. */
class ConversationResource extends JsonResource
{
    /** Handles to array for the conversation resource workflow. */
    public function toArray(Request $request):array
    {
        $participant=$this->participants->firstWhere('user_id',$request->user()?->id);$lastRead=$participant?->last_read_at;
        $unread=$this->getAttribute('unread_count'); if($unread===null)$unread=$this->messages->filter(/** Inline callback for this operation. */ fn($m)=>$m->sender_user_id!==$request->user()?->id&&(!$lastRead||$m->created_at->gt($lastRead)))->count();$last=$this->messages->sortByDesc('id')->first();
        $participantRows=$this->participants->map(/** Inline callback for this operation. */ function(ConversationParticipant $p){$name=$p->user?->name;if($p->participant_role==='seller'&&$this->vendor)$name=$this->vendor->name;return ['id'=>$p->user_id,'name'=>$name,'role'=>$p->participant_role];})->values()->all();
        $lastSenderName=$last?->sender?->name;if($last&&$this->kind==='order'&&$this->vendor&&$this->vendor->owner_user_id===$last->sender_user_id)$lastSenderName=$this->vendor->name;
        return ['id'=>$this->public_id,'kind'=>$this->kind,'subject'=>$this->subject,'status'=>$this->status,'orderId'=>$this->order?->public_id,'vendorOrderId'=>$this->vendorOrder?->public_id,'vendor'=>$this->vendor ? ['id'=>$this->vendor->id,'name'=>$this->vendor->name] : null,'participants'=>$participantRows,'unreadCount'=>$unread,'lastMessage'=>$last?['body'=>$last->body,'senderName'=>$lastSenderName,'createdAt'=>$last->created_at?->toISOString()]:null,'lastMessageAt'=>$this->last_message_at?->toISOString()];
    }
}
