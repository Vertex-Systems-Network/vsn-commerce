<?php

namespace App\Models;

use App\Enums\CartStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the Cart class and its project responsibilities. */
class Cart extends Model
{
    use HasFactory;

    protected $hidden = ['mysql_active_user_guard'];

    protected $fillable = [
        'public_id',
        'user_id',
        'guest_token',
        'status',
        'currency',
        'metadata',
    ];

    /** Handles casts for the cart workflow. */
    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'metadata' => 'array',
        ];
    }

    /** Handles user for the cart workflow. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Handles items for the cart workflow. */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->oldest('id');
    }

    /** Handles checkout sessions for the cart workflow. */
    public function checkoutSessions(): HasMany
    {
        return $this->hasMany(CheckoutSession::class);
    }
}
