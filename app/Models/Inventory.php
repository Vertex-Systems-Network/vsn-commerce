<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the Inventory class and its project responsibilities. */
class Inventory extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'product_variant_id',
        'on_hand',
        'reserved',
        'safety_stock',
    ];

    /** Handles casts for the inventory workflow. */
    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'safety_stock' => 'integer',
        ];
    }

    /** Handles available for the inventory workflow. */
    public function available(): int
    {
        return max(0, $this->on_hand - $this->reserved - $this->safety_stock);
    }

    /** Handles warehouse for the inventory workflow. */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Handles variant for the inventory workflow. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Handles reservations for the inventory workflow. */
    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }
}
