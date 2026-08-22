<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the MobileApiSession class and its project responsibilities. */
class MobileApiSession extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'access_token_id', 'refresh_token_hash', 'previous_refresh_token_hash',
        'refresh_generation', 'device_key_hash', 'device_name', 'platform', 'app_version', 'os_version',
        'push_token', 'push_token_hash', 'push_provider', 'push_token_updated_at', 'last_ip', 'last_seen_at',
        'refresh_expires_at', 'last_rotated_at', 'compromised_at', 'compromise_reason', 'revoked_at',
    ];

    protected $hidden = ['refresh_token_hash', 'previous_refresh_token_hash', 'push_token', 'push_token_hash'];

    /** Handles casts for the mobile api session workflow. */
    protected function casts(): array
    {
        return [
            'push_token' => 'encrypted',
            'refresh_generation' => 'integer',
            'push_token_updated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_rotated_at' => 'datetime',
            'compromised_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Returns route key name. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Handles user for the mobile api session workflow. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Handles active for the mobile api session workflow. */
    public function active(): bool
    {
        return $this->revoked_at === null && $this->compromised_at === null;
    }
}
