<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the SocialAccount class and its project responsibilities. */
class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
        'metadata',
    ];

    /** Handles casts for the social account workflow. */
    protected function casts(): array
    {
        return ['metadata' => 'encrypted:array'];
    }

    /** Handles user for the social account workflow. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
