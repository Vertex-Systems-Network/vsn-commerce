<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
/** Defines the CoinPurchaseResource class and its project responsibilities. */
class CoinPurchaseResource extends JsonResource
{
    /** Handles to array for the coin purchase resource workflow. */
    public function toArray(Request $request): array
    {
        $intent=$this->paymentIntent;
        return ['id'=>$this->public_id,'coins'=>$this->coins,'currency'=>$this->currency,'amountMinor'=>$this->amount_minor,'status'=>$this->status->value,'paidAt'=>$this->paid_at?->toISOString(),
            'payment'=>$intent ? (new PaymentIntentResource($intent))->toArray($request) : null];
    }
}
