<?php
namespace App\Models;
use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the Dispute class and its project responsibilities. */
class Dispute extends Model
{
    protected $fillable=['public_id','return_request_id','order_id','opened_by_user_id','status','outcome','resolution_note','resolved_by_user_id','opened_at','resolved_at','metadata'];
    /** Handles casts for the dispute workflow. */
    protected function casts(): array { return ['status'=>DisputeStatus::class,'opened_at'=>'datetime','resolved_at'=>'datetime','metadata'=>'array']; }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles request for the dispute workflow. */
    public function request(): BelongsTo { return $this->belongsTo(ReturnRequest::class,'return_request_id'); }
    /** Handles order for the dispute workflow. */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    /** Handles opened by for the dispute workflow. */
    public function openedBy(): BelongsTo { return $this->belongsTo(User::class,'opened_by_user_id'); }
    /** Handles resolved by for the dispute workflow. */
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class,'resolved_by_user_id'); }
    /** Handles messages for the dispute workflow. */
    public function messages(): HasMany { return $this->hasMany(DisputeMessage::class); }
}
