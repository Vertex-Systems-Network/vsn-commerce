<?php
namespace App\Models;
use App\Enums\ReturnRequestStatus;
use App\Enums\ReturnResolution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
/** Defines the ReturnRequest class and its project responsibilities. */
class ReturnRequest extends Model
{
    protected $fillable=['public_id','user_id','order_id','status','resolution','reason','details','currency','requested_minor','approved_minor','return_tracking_reference','return_carrier','shipped_at','submitted_at','reviewed_at','received_at','inspection_completed_at','resolved_at','cancelled_at','metadata'];
    /** Handles casts for the return request workflow. */
    protected function casts(): array { return ['status'=>ReturnRequestStatus::class,'resolution'=>ReturnResolution::class,'requested_minor'=>'integer','approved_minor'=>'integer','submitted_at'=>'datetime','reviewed_at'=>'datetime','received_at'=>'datetime','inspection_completed_at'=>'datetime','resolved_at'=>'datetime','cancelled_at'=>'datetime','shipped_at'=>'datetime','metadata'=>'array']; }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles user for the return request workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles order for the return request workflow. */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    /** Handles items for the return request workflow. */
    public function items(): HasMany { return $this->hasMany(ReturnRequestItem::class); }
    /** Handles refund for the return request workflow. */
    public function refund(): HasOne { return $this->hasOne(Refund::class); }
    /** Handles dispute for the return request workflow. */
    public function dispute(): HasOne { return $this->hasOne(Dispute::class); }
}
