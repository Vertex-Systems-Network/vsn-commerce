<?php

namespace App\Models;

use App\Enums\InventoryReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the InventoryReservation class and its project responsibilities. */
class InventoryReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_id',
        'user_id',
        'idempotency_key',
        'quantity',
        'status',
        'reference',
        'expires_at',
        'released_at',
        'converted_at',
    ];

    /** Handles casts for the inventory reservation workflow. */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => InventoryReservationStatus::class,
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    /** Handles inventory for the inventory reservation workflow. */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /** Handles user for the inventory reservation workflow. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
