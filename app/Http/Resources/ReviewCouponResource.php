<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Defines the ReviewCouponResource class and its project responsibilities. */
class ReviewCouponResource extends JsonResource
{
    /** Handles to array for the review coupon resource workflow. */
    public function toArray(Request $request): array
    {
        return [
            'id'=>$this->public_id,
            'code'=>$this->code,
            'percent'=>(float) ($this->percent_bps / 100),
            'percentBps'=>$this->percent_bps,
            'status'=>$this->status->value,
            'issuedAt'=>$this->issued_at?->toISOString(),
            'expiresAt'=>$this->expires_at?->toISOString(),
            'redeemedAt'=>$this->redeemed_at?->toISOString(),
            'reviewId'=>$this->review?->public_id,
            'productName'=>$this->review?->product?->name ?? $this->review?->orderItem?->product_name,
        ];
    }
}
