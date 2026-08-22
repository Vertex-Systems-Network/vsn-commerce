<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
/** Defines the ProductAlertResource class and its project responsibilities. */
class ProductAlertResource extends JsonResource
{
    /** Handles to array for the product alert resource workflow. */
    public function toArray(Request $request):array{return ['id'=>$this->public_id,'type'=>$this->type->value,'status'=>$this->status->value,'targetPriceMinor'=>$this->target_price_minor,'lastObservedPriceMinor'=>$this->last_observed_price_minor,'lastObservedStock'=>$this->last_observed_stock,'triggeredAt'=>$this->triggered_at?->toIso8601String(),'createdAt'=>$this->created_at?->toIso8601String(),'product'=>['id'=>$this->product?->public_id,'slug'=>$this->product?->slug,'name'=>$this->product?->name,'priceMinor'=>$this->product?->base_price_minor,'image'=>$this->product?->images?->first()?->url],'variant'=>$this->variant?['id'=>$this->variant->id,'name'=>$this->variant->name,'options'=>$this->variant->option_values]:null];}
}
