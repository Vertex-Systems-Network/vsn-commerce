<?php

namespace App\Http\Resources;

use App\Models\ConversationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Defines the ConversationMessageResource class and its project responsibilities.
 *
 * @mixin ConversationMessage
 */
class ConversationMessageResource extends JsonResource
{
    /** Handles to array for the conversation message resource workflow. */
    public function toArray(Request $request): array
    {
        $name = $this->sender?->name;
        $conversation = $this->relationLoaded('conversation') ? $this->conversation : null;

        if ($conversation?->kind === 'order' && $conversation->vendor && $conversation->vendor->owner_user_id === $this->sender_user_id) {
            $name = $conversation->vendor->name;
        }

        return [
            'id' => $this->public_id,
            'body' => $this->body,
            'sender' => [
                'id' => $this->sender?->id,
                'name' => $name,
                'me' => $this->sender_user_id === $request->user()?->id,
            ],
            'attachments' => $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->public_id,
                'name' => $attachment->original_name,
                'mimeType' => $attachment->mime_type,
                'sizeBytes' => $attachment->size_bytes,
                'downloadUrl' => "/messages/attachments/{$attachment->public_id}",
            ])->values()->all(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
