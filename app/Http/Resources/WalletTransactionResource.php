<?php
namespace App\Http\Resources;
use App\Enums\WalletEntryDirection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
/** Defines the WalletTransactionResource class and its project responsibilities. */
class WalletTransactionResource extends JsonResource
{
    /** Handles to array for the wallet transaction resource workflow. */
    public function toArray(Request $request): array
    {
        $entry = $this->entries->firstWhere('user_id',$request->user()?->id);
        return [
            'id'=>$this->public_id,'type'=>$this->type->value,'status'=>$this->status,'referenceType'=>$this->reference_type,'referenceId'=>$this->reference_id,
            'direction'=>$entry?->direction?->value,'coins'=>$entry?->coins ?? 0,'balanceAfterCoins'=>$entry?->balance_after_coins,
            'metadata'=>$this->metadata ?? new \stdClass(),'occurredAt'=>$this->occurred_at?->toISOString(),
        ];
    }
}
