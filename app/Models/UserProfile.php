<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the UserProfile class and its project responsibilities. */
class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone',
        'phone_verified_at',
        'avatar_path',
        'date_of_birth',
        'locale',
        'timezone',
        'metadata',
    ];

    /** Handles casts for the user profile workflow. */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'metadata' => 'array',
        ];
    }

    /** Handles user for the user profile workflow. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
