<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the Address class and its project responsibilities. */
class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'phone',
        'line1',
        'line2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'is_default',
    ];

    /** Handles casts for the address workflow. */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** Handles user for the address workflow. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
