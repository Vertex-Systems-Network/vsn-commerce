<?php

namespace App\Domain\Messaging\Actions;

use App\Domain\Messaging\Exceptions\MessagingException;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\User;
use App\Models\VendorOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the OpenConversation class and its project responsibilities. */
class OpenConversation
{
    /** Executes the open conversation operation. */
    public function execute(User $user, string $kind, ?string $vendorOrderPublicId = null): Conversation
    {
        if ($kind === 'support') {
            return $this->support($user);
        }
        if ($kind !== 'order' || ! $vendorOrderPublicId) {
            throw new MessagingException('A seller order is required for order chat.', 'vendorOrderId');
        }
        $vendorOrder = VendorOrder::query()->where('public_id', $vendorOrderPublicId)->with(['order.user', 'vendor.owner'])->first();
        if (! $vendorOrder || ! $vendorOrder->order || ! $vendorOrder->vendor) {
            throw new MessagingException('Seller order was not found.', 'vendorOrderId');
        }
        $buyer = $vendorOrder->order->user;
        $seller = $vendorOrder->vendor->owner;
        if (! $buyer || ! $seller) {
            throw new MessagingException('This seller order cannot open a conversation.', 'vendorOrderId');
        }
        if (! in_array($user->id, [$buyer->id, $seller->id], true)) {
            throw new MessagingException('You cannot message this seller order.', 'vendorOrderId');
        }

        return DB::transaction(/** Inline callback for this operation. */ function () use ($user, $vendorOrder, $buyer, $seller) {
            $conversation = $this->findOrInsertConversation('order:'.$vendorOrder->id, ['kind' => 'order', 'subject' => "Order {$vendorOrder->order->public_id} · {$vendorOrder->vendor->name}", 'order_id' => $vendorOrder->order_id, 'vendor_order_id' => $vendorOrder->id, 'vendor_id' => $vendorOrder->vendor_id, 'created_by_user_id' => $user->id, 'status' => 'open']);
            $conversation->participants()->firstOrCreate(['user_id' => $buyer->id], ['participant_role' => 'customer', 'joined_at' => now()]);
            $conversation->participants()->firstOrCreate(['user_id' => $seller->id], ['participant_role' => 'seller', 'joined_at' => now()]);

            return $conversation->fresh(['order', 'vendorOrder', 'vendor', 'participants.user']);
        }, 3);
    }

    /** Handles support for the open conversation workflow. */
    private function support(User $user): Conversation
    {
        $role = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;
        if (in_array($role, [UserRole::Support->value, UserRole::Admin->value, UserRole::SuperAdmin->value], true)) {
            throw new MessagingException('Support staff should open a customer support thread from the support inbox.', 'kind');
        }

        return DB::transaction(/** Inline callback for this operation. */ function () use ($user) {
            $conversation = $this->findOrInsertConversation('support:user:'.$user->id, ['kind' => 'support', 'subject' => 'VSN Support', 'created_by_user_id' => $user->id, 'status' => 'open']);
            $conversation->participants()->firstOrCreate(['user_id' => $user->id], ['participant_role' => 'customer', 'joined_at' => now()]);

            return $conversation->fresh('participants.user');
        }, 3);
    }

    /** Inserts a conversation without using a duplicate-key exception as control flow. */
    private function findOrInsertConversation(string $threadKey, array $attributes): Conversation
    {
        $now = now();
        $inserted = DB::table('conversations')->insertOrIgnore(array_merge([
            'thread_key' => $threadKey,
            'public_id' => (string) Str::uuid(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));
        $conversation = Conversation::query()->where('thread_key', $threadKey)->firstOrFail();
        $conversation->wasRecentlyCreated = $inserted === 1;

        return $conversation;
    }
}
