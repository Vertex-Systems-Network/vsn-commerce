<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the MobileOAuthFlow class and its project responsibilities. */
class MobileOAuthFlow extends Model
{
    protected $fillable = [
        'public_id', 'provider', 'state_hash', 'device_key_hash', 'user_id', 'exchange_code_hash',
        'expires_at', 'completed_at', 'consumed_at',
    ];

    protected $hidden = ['state_hash', 'exchange_code_hash', 'device_key_hash'];

    /** Handles casts for the mobile oauth flow workflow. */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'completed_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    /** Handles user for the mobile oauth flow workflow. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
