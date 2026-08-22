<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the ReturnRequestItem class and its project responsibilities. */
class ReturnRequestItem extends Model
{
    protected $fillable=['return_request_id','order_item_id','quantity','approved_quantity','received_quantity','accepted_quantity','requested_minor','approved_minor','restock','condition','inspection_note','restocked_at','metadata'];
    /** Handles casts for the return request item workflow. */
    protected function casts(): array { return ['quantity'=>'integer','approved_quantity'=>'integer','received_quantity'=>'integer','accepted_quantity'=>'integer','requested_minor'=>'integer','approved_minor'=>'integer','restock'=>'boolean','restocked_at'=>'datetime','metadata'=>'array']; }
    /** Handles request for the return request item workflow. */
    public function request(): BelongsTo { return $this->belongsTo(ReturnRequest::class,'return_request_id'); }
    /** Handles order item for the return request item workflow. */
    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }
}
