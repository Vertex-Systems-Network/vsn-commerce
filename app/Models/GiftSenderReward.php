<?php

namespace App\Models;

use App\Enums\GiftRewardStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the GiftSenderReward class and its project responsibilities. */
class GiftSenderReward extends Model
{
    use HasFactory;
    protected $fillable = ['public_id','user_id','reward_code','level','status','source_event_id','metadata','awarded_at','consumed_at'];
    /** Handles casts for the gift sender reward workflow. */
    protected function casts(): array { return ['status'=>GiftRewardStatus::class,'metadata'=>'array','awarded_at'=>'datetime','consumed_at'=>'datetime']; }
    /** Handles user for the gift sender reward workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles source event for the gift sender reward workflow. */
    public function sourceEvent(): BelongsTo { return $this->belongsTo(GiftSenderEvent::class, 'source_event_id'); }
}
