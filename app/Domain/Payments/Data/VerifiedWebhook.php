<?php

namespace App\Domain\Payments\Data;

use Carbon\CarbonImmutable;

/** Defines the VerifiedWebhook class and its project responsibilities. */
class VerifiedWebhook
{
    /** Initializes the VerifiedWebhook instance and its dependencies. */
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $paymentIntentPublicId,
        public readonly ?string $providerPaymentId,
        public readonly ?string $providerTransactionId,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly CarbonImmutable $occurredAt,
        public readonly array $payload,
    ) {}
}
