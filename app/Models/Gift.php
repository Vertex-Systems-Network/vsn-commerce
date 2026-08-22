<?php

namespace App\Models;

use App\Enums\GiftStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the Gift class and its project responsibilities. */
class Gift extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'sender_user_id', 'recipient_user_id', 'checkout_session_id', 'order_id',
        'product_id', 'product_variant_id', 'status', 'currency', 'product_value_minor',
        'gift_wrap_minor', 'gift_value_minor', 'gift_value_coins', 'message', 'anonymous',
        'gift_wrap', 'scheduled_for', 'paid_at', 'progress_recorded_at', 'recipient_notified_at',
        'idempotency_key', 'metadata',
    ];

    /** Handles casts for the gift workflow. */
    protected function casts(): array
    {
        return [
            'status' => GiftStatus::class,
            'product_value_minor' => 'integer',
            'gift_wrap_minor' => 'integer',
            'gift_value_minor' => 'integer',
            'gift_value_coins' => 'integer',
            'anonymous' => 'boolean',
            'gift_wrap' => 'boolean',
            'scheduled_for' => 'datetime',
            'paid_at' => 'datetime',
            'progress_recorded_at' => 'datetime',
            'recipient_notified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles sender for the gift workflow. */
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_user_id'); }
    /** Handles recipient for the gift workflow. */
    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_user_id'); }
    /** Handles checkout session for the gift workflow. */
    public function checkoutSession(): BelongsTo { return $this->belongsTo(CheckoutSession::class); }
    /** Handles order for the gift workflow. */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    /** Handles product for the gift workflow. */
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    /** Handles variant for the gift workflow. */
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    /** Handles notifications for the gift workflow. */
    public function notifications(): HasMany { return $this->hasMany(GiftNotification::class); }
}
