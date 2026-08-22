<?php

namespace App\Domain\Reviews\Services;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Defines the ReviewEligibility class and its project responsibilities. */
class ReviewEligibility
{
    /** Handles query for the review eligibility workflow. */
    public function query(User $user): Builder
    {
        return OrderItem::query()
            ->whereHas('order', /** Inline callback for this operation. */ fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->whereNotNull('delivered_at')
                ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::PartiallyRefunded->value]))
            ->whereColumn('refunded_quantity', '<', 'quantity')
            ->whereDoesntHave('review');
    }

    /** Handles pending for the review eligibility workflow. */
    public function pending(User $user): Collection
    {
        return $this->query($user)
            ->with(['order','product.images','variant'])
            ->latest('id')
            ->get();
    }

    /** Handles is eligible for the review eligibility workflow. */
    public function isEligible(OrderItem $item, User $user): bool
    {
        $item->loadMissing('order');
        return $item->order?->user_id === $user->id
            && $item->order?->delivered_at !== null
            && in_array($item->order?->status, [OrderStatus::Delivered, OrderStatus::PartiallyRefunded], true)
            && (int) $item->refunded_quantity < (int) $item->quantity;
    }
}
