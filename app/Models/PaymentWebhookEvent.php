<?php

namespace App\Models;

use App\Enums\PaymentWebhookStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the PaymentWebhookEvent class and its project responsibilities. */
class PaymentWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_intent_id', 'provider', 'provider_event_id', 'event_type', 'status',
        'payload_sha256', 'payload', 'processing_error', 'received_at', 'processed_at',
    ];

    /** Handles casts for the payment webhook event workflow. */
    protected function casts(): array
    {
        return [
            'status' => PaymentWebhookStatus::class,
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** Handles payment intent for the payment webhook event workflow. */
    public function paymentIntent(): BelongsTo { return $this->belongsTo(PaymentIntent::class); }
}
