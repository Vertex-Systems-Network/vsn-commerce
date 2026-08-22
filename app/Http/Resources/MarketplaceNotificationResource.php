<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
/** Defines the MarketplaceNotificationResource class and its project responsibilities. */
class MarketplaceNotificationResource extends JsonResource
{
    /** Handles to array for the marketplace notification resource workflow. */
    public function toArray(Request $request):array{return ['id'=>$this->public_id,'category'=>$this->category,'type'=>$this->type,'title'=>$this->title,'body'=>$this->body,'actionUrl'=>$this->action_url,'reference'=>['type'=>$this->reference_type,'id'=>$this->reference_id],'data'=>$this->data??[],'read'=>$this->read_at!==null,'readAt'=>$this->read_at?->toISOString(),'createdAt'=>$this->created_at?->toISOString()];}
}
