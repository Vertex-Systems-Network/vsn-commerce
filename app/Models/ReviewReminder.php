<?php

namespace App\Models;

use App\Enums\ReviewReminderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the ReviewReminder class and its project responsibilities. */
class ReviewReminder extends Model
{
    protected $fillable = ['user_id','order_id','order_item_id','product_id','status','scheduled_for','queued_at','sent_at','attempts','last_error','metadata'];
    /** Handles casts for the review reminder workflow. */
    protected function casts(): array { return ['status'=>ReviewReminderStatus::class,'scheduled_for'=>'datetime','queued_at'=>'datetime','sent_at'=>'datetime','attempts'=>'integer','metadata'=>'array']; }
    /** Handles user for the review reminder workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles order for the review reminder workflow. */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    /** Handles order item for the review reminder workflow. */
    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }
    /** Handles product for the review reminder workflow. */
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
