<?php

use App\Domain\Messaging\Services\ConversationAccess;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', /** Inline callback for this operation. */ fn (User $user, int $id): bool => $user->id === $id);
Broadcast::channel('conversation.{conversationId}', /** Inline callback for this operation. */ function (User $user, string $conversationId): bool {
    $conversation=Conversation::query()->where('public_id',$conversationId)->first();
    return $conversation ? app(ConversationAccess::class)->canAccess($user,$conversation) : false;
});

Broadcast::channel('support.inbox', /** Inline callback for this operation. */ function (User $user): bool {
    $role=$user->role instanceof \App\Enums\UserRole ? $user->role->value : (string)$user->role;
    return in_array($role,[\App\Enums\UserRole::Support->value,\App\Enums\UserRole::Admin->value,\App\Enums\UserRole::SuperAdmin->value],true);
});
