<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
/** Defines the ReturnRequestResource class and its project responsibilities. */
class ReturnRequestResource extends JsonResource
{
    /** Handles to array for the return request resource workflow. */
    public function toArray(Request $request): array
    {
        $role=$request->user()?->role?->value ?? (string)$request->user()?->role;
        $isAdmin=in_array($role,['admin','super_admin'],true);
        return [
            'id'=>$this->public_id,'orderId'=>$this->order?->public_id,'status'=>$this->status->value,'resolution'=>$this->resolution->value,'reason'=>$this->reason,'details'=>$this->details,
            'currency'=>$this->currency,'requestedMinor'=>(int)$this->requested_minor,'approvedMinor'=>(int)$this->approved_minor,
            'trackingReference'=>$this->return_tracking_reference,'carrier'=>$this->return_carrier,
            'submittedAt'=>$this->submitted_at?->toISOString(),'reviewedAt'=>$this->reviewed_at?->toISOString(),'shippedAt'=>$this->shipped_at?->toISOString(),'receivedAt'=>$this->received_at?->toISOString(),'inspectionCompletedAt'=>$this->inspection_completed_at?->toISOString(),'resolvedAt'=>$this->resolved_at?->toISOString(),'cancelledAt'=>$this->cancelled_at?->toISOString(),
            'items'=>$this->items->map(/** Inline callback for this operation. */ fn($row)=>[
                'id'=>$row->id,'orderItemId'=>$row->order_item_id,'productName'=>$row->orderItem?->product_name,'variantName'=>$row->orderItem?->variant_name,
                'quantity'=>(int)$row->quantity,'approvedQuantity'=>(int)$row->approved_quantity,'receivedQuantity'=>(int)$row->received_quantity,'acceptedQuantity'=>(int)$row->accepted_quantity,
                'requestedMinor'=>(int)$row->requested_minor,'approvedMinor'=>(int)$row->approved_minor,'restock'=>(bool)$row->restock,'condition'=>$row->condition,'inspectionNote'=>$isAdmin?$row->inspection_note:null,'restockedAt'=>$row->restocked_at?->toISOString()
            ])->values(),
            'refund'=>$this->refund?[
                'id'=>$this->refund->public_id,'status'=>$this->refund->status->value,'amountMinor'=>(int)$this->refund->amount_minor,'cashRefundMinor'=>(int)$this->refund->cash_refund_minor,'coinRefundCoins'=>(int)$this->refund->coin_refund_coins,
                'attemptCount'=>(int)$this->refund->attempt_count,'manualReference'=>$isAdmin?$this->refund->manual_reference:null,'processedAt'=>$this->refund->processed_at?->toISOString(),
                'events'=>$this->refund->relationLoaded('events')?$this->refund->events->map(/** Inline callback for this operation. */ fn($e)=>['event'=>$e->event,'reference'=>$isAdmin?$e->reference:null,'message'=>$e->message,'occurredAt'=>$e->occurred_at?->toISOString()])->values():[],
            ]:null,
            'dispute'=>$this->dispute?['id'=>$this->dispute->public_id,'status'=>$this->dispute->status->value,'outcome'=>$this->dispute->outcome,'resolutionNote'=>$this->dispute->resolution_note]:null,
            'sellerFeedback'=>$this->when($isAdmin,array_values((array)(($this->metadata??[])['seller_feedback']??[]))),
        ];
    }
}
