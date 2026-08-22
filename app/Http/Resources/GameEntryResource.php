<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Defines the GameEntryResource class and its project responsibilities. */
class GameEntryResource extends JsonResource
{
    /** Handles to array for the game entry resource workflow. */
    public function toArray(Request $request): array
    {
        $game=$this->game; $product=$game?->product; $image=$product?->images?->first();
        return [
            'id'=>$this->public_id,
            'quantity'=>$this->quantity,
            'coinsSpent'=>$this->coins_spent,
            'rulesVersion'=>$this->rules_version,
            'enteredAt'=>$this->created_at?->toISOString(),
            'refunded'=>(bool)$this->refund,
            'refund'=> $this->refund ? ['reason'=>$this->refund->reason,'refundedAt'=>$this->refund->refunded_at?->toISOString()] : null,
            'game'=> $game ? [
                'id'=>$game->public_id,
                'status'=>$game->status->value,
                'entryCoins'=>$game->entry_coins,
                'announcementAt'=>$game->announcement_at?->toISOString(),
                'commitmentHash'=>$game->commitment_hash,
                'product'=>['id'=>$product?->id,'slug'=>$product?->slug,'name'=>$product?->name,'image'=>$image?->url],
                'isWinner'=>$game->draw?->winner_entry_id === $this->id,
            ] : null,
        ];
    }
}
