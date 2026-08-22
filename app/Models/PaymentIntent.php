<?php

namespace App\Models;

use App\Enums\PaymentIntentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the PaymentIntent class and its project responsibilities. */
class PaymentIntent extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'user_id', 'checkout_session_id', 'order_id', 'idempotency_key', 'purpose', 'reference_type', 'reference_id',
        'provider', 'payment_method', 'saved_payment_method_id', 'status', 'currency', 'amount_minor',
        'provider_payment_id', 'client_action', 'metadata', 'expires_at',
        'authorized_at', 'paid_at', 'failed_at', 'initialization_attempts', 'last_initialization_attempt_at', 'provider_status', 'provider_synced_at', 'provider_sync_error',
    ];

    /** Handles casts for the payment intent workflow. */
    protected function casts(): array
    {
        return [
            'status' => PaymentIntentStatus::class,
            'amount_minor' => 'integer',
            'client_action' => 'array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'authorized_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'initialization_attempts' => 'integer',
            'last_initialization_attempt_at' => 'datetime',
            'provider_synced_at' => 'datetime',
        ];
    }

    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles user for the payment intent workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles checkout session for the payment intent workflow. */
    public function checkoutSession(): BelongsTo { return $this->belongsTo(CheckoutSession::class); }
    /** Handles saved payment method for the payment intent workflow. */
    public function savedPaymentMethod(): BelongsTo { return $this->belongsTo(SavedPaymentMethod::class); }

    /** Handles order for the payment intent workflow. */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    /** Handles transactions for the payment intent workflow. */
    public function transactions(): HasMany { return $this->hasMany(PaymentTransaction::class); }
    /** Handles webhook events for the payment intent workflow. */
    public function webhookEvents(): HasMany { return $this->hasMany(PaymentWebhookEvent::class); }
    /** Handles coin purchase for the payment intent workflow. */
    public function coinPurchase() { return $this->hasOne(CoinPurchase::class); }
}
