<?php

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Defines the GameResource class and its project responsibilities.
 *
 * @mixin Game
 */
class GameResource extends JsonResource
{
    /** Handles to array for the game resource workflow. */
    public function toArray(Request $request): array
    {
        $image = $this->product?->images?->first();
        $draw = $this->draw;

        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'entryCoins' => $this->entry_coins,
            'winnerBonusCoins' => (int) $this->winner_bonus_coins,
            'maxEntries' => $this->max_entries,
            'maxEntriesPerUser' => $this->max_entries_per_user ?: (int) config('vsn.games.max_entries_per_user', 100),
            'totalEntries' => $this->total_entries,
            'entriesRemaining' => $this->max_entries === null ? null : max(0, $this->max_entries - $this->total_entries),
            'opensAt' => $this->opens_at?->toISOString(),
            'closesAt' => $this->closes_at?->toISOString(),
            'announcementAt' => $this->announcement_at?->toISOString(),
            'rulesVersion' => $this->rules_version,
            'commitmentHash' => $this->commitment_hash,
            'cancelledAt' => $this->cancelled_at?->toISOString(),
            'cancellationReason' => $this->cancellation_reason,
            'fulfilledAt' => $this->fulfilled_at?->toISOString(),
            'product' => [
                'id' => $this->product?->id,
                'publicId' => $this->product?->public_id,
                'slug' => $this->product?->slug,
                'name' => $this->product?->name,
                'image' => $image?->url,
                'currency' => $this->product?->currency,
                'priceMinor' => $this->product?->base_price_minor,
                'vendor' => $this->product?->vendor?->name,
            ],
            'fulfillment' => $this->fulfillment ? [
                'method' => $this->fulfillment->method,
                'reference' => $this->fulfillment->reference,
                'fulfilledAt' => $this->fulfillment->fulfilled_at?->toISOString(),
                'winnerBonusCoins' => (int) $this->winner_bonus_coins,
                'walletTransactionId' => $this->fulfillment->walletTransaction?->public_id,
            ] : null,
            'draw' => $draw ? [
                'id' => $draw->public_id,
                'snapshotHash' => $draw->snapshot_hash,
                'revealedSecret' => $draw->revealed_secret,
                'selectionHash' => $draw->selection_hash,
                'totalTickets' => $draw->total_tickets,
                'winningTicketNumber' => $draw->winning_ticket_number,
                'snapshot' => $draw->snapshot,
                'snapshotCanonical' => $draw->snapshot_canonical,
                'winner' => [
                    'name' => $draw->winner?->name ? mb_substr($draw->winner->name, 0, 1).'***' : 'Verified participant',
                    'entryId' => $draw->winningEntry?->public_id,
                ],
                'drawnAt' => $draw->drawn_at?->toISOString(),
                'auditFormula' => 'sha256(revealedSecret|snapshotHash|gameId), first 48 bits modulo totalTickets + 1',
            ] : null,
        ];
    }
}
