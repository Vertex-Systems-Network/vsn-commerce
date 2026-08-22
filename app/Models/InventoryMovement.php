<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the InventoryMovement class and its project responsibilities. */
class InventoryMovement extends Model
{
    protected $fillable = [
        'inventory_id',
        'type',
        'on_hand_delta',
        'reserved_delta',
        'reference_type',
        'reference_id',
        'metadata',
    ];

    /** Handles casts for the inventory movement workflow. */
    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'on_hand_delta' => 'integer',
            'reserved_delta' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** Handles inventory for the inventory movement workflow. */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
