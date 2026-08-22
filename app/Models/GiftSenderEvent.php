<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the GiftSenderEvent class and its project responsibilities. */
class GiftSenderEvent extends Model
{
    use HasFactory;
    protected $fillable = ['public_id','user_id','event_type','gift_coins','idempotency_key','reference_type','reference_id','metadata','occurred_at'];
    /** Handles casts for the gift sender event workflow. */
    protected function casts(): array { return ['gift_coins'=>'integer','metadata'=>'array','occurred_at'=>'datetime']; }
    /** Handles user for the gift sender event workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
