<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Defines the AffiliateCommissionResource class and its project responsibilities. */
class AffiliateCommissionResource extends JsonResource
{
    /** Handles to array for the affiliate commission resource workflow. */
    public function toArray(Request $request): array
    {
        return [
            'id'=>$this->public_id,
            'orderId'=>$this->order?->public_id,
            'level'=>$this->level_no,
            'rateBps'=>$this->rate_bps,
            'rate'=>$this->rate_bps/100,
            'currency'=>$this->currency,
            'eligibleSpendMinor'=>$this->eligible_spend_minor,
            'rewardCoins'=>$this->reward_coins,
            'status'=>$this->status->value,
            'availableAt'=>$this->available_at?->toISOString(),
            'creditedAt'=>$this->credited_at?->toISOString(),
            'reversedAt'=>$this->reversed_at?->toISOString(),
        ];
    }
}
