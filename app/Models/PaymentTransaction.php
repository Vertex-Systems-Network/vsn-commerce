<?php

namespace App\Models;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the PaymentTransaction class and its project responsibilities. */
class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'payment_intent_id', 'order_id', 'provider', 'type', 'status',
        'currency', 'amount_minor', 'provider_transaction_id', 'idempotency_key',
        'metadata', 'occurred_at',
    ];

    /** Handles casts for the payment transaction workflow. */
    protected function casts(): array
    {
        return [
            'type' => PaymentTransactionType::class,
            'status' => PaymentTransactionStatus::class,
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** Handles payment intent for the payment transaction workflow. */
    public function paymentIntent(): BelongsTo { return $this->belongsTo(PaymentIntent::class); }
    /** Handles order for the payment transaction workflow. */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
