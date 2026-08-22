<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the Warehouse class and its project responsibilities. */
class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'is_active',
        'metadata',
    ];

    /** Handles casts for the warehouse workflow. */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** Handles inventories for the warehouse workflow. */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }
}
