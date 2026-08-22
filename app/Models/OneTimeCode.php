<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Defines the OneTimeCode class and its project responsibilities. */
class OneTimeCode extends Model
{
    protected $fillable = [
        'purpose',
        'identifier',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = ['code_hash'];

    /** Handles casts for the one time code workflow. */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
