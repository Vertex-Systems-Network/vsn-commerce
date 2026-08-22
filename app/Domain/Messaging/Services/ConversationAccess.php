<?php
namespace App\Domain\Messaging\Services;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\User;
/** Defines the ConversationAccess class and its project responsibilities. */
class ConversationAccess
{
    /** Handles can access for the conversation access workflow. */
    public function canAccess(User $user,Conversation $conversation):bool
    {
        if($conversation->participants()->where('user_id',$user->id)->exists())return true;
        $role=$user->role instanceof UserRole?$user->role->value:(string)$user->role;
        return $conversation->kind==='support'&&in_array($role,[UserRole::Support->value,UserRole::Admin->value,UserRole::SuperAdmin->value],true);
    }
    /** Handles assert for the conversation access workflow. */
    public function assert(User $user,Conversation $conversation):void{abort_unless($this->canAccess($user,$conversation),404);}
}
