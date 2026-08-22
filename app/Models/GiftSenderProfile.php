<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the GiftSenderProfile class and its project responsibilities. */
class GiftSenderProfile extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'lifetime_gift_coins', 'current_level'];
    /** Handles casts for the gift sender profile workflow. */
    protected function casts(): array { return ['lifetime_gift_coins' => 'integer']; }
    /** Handles user for the gift sender profile workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles events for the gift sender profile workflow. */
    public function events(): HasMany { return $this->hasMany(GiftSenderEvent::class, 'user_id', 'user_id'); }
}
