<?php
namespace App\Domain\Messaging\Services;
use App\Enums\UserRole;
use App\Models\ConversationMessage;
use App\Models\User;
/** Defines the UnreadMessageCounter class and its project responsibilities. */
class UnreadMessageCounter
{
    /** Handles for user for the unread message counter workflow. */
    public function forUser(User $user):int
    {
        $id=$user->id;$role=$user->role instanceof UserRole?$user->role->value:(string)$user->role;$support=in_array($role,[UserRole::Support->value,UserRole::Admin->value,UserRole::SuperAdmin->value],true);
        return ConversationMessage::query()->where('sender_user_id','!=',$id)
            ->whereHas('conversation',/** Inline callback for this operation. */ function($q)use($id,$support){$q->where(/** Inline callback for this operation. */ function($scope)use($id,$support){$scope->whereHas('participants',/** Inline callback for this operation. */ fn($p)=>$p->where('user_id',$id));if($support)$scope->orWhere('kind','support');});})
            ->where(/** Inline callback for this operation. */ function($q)use($id){
                $q->whereNotExists(/** Inline callback for this operation. */ function($sub)use($id){$sub->selectRaw('1')->from('conversation_participants as cp')->whereColumn('cp.conversation_id','conversation_messages.conversation_id')->where('cp.user_id',$id);})
                  ->orWhereExists(/** Inline callback for this operation. */ function($sub)use($id){$sub->selectRaw('1')->from('conversation_participants as cp')->whereColumn('cp.conversation_id','conversation_messages.conversation_id')->where('cp.user_id',$id)->where(/** Inline callback for this operation. */ function($state){$state->whereNull('cp.last_read_at')->orWhereColumn('conversation_messages.created_at','>','cp.last_read_at');});});
            })->count();
    }
}
