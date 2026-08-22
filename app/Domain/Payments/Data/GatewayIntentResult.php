<?php

namespace App\Domain\Payments\Data;

/** Defines the GatewayIntentResult class and its project responsibilities. */
class GatewayIntentResult
{
    /** Initializes the GatewayIntentResult instance and its dependencies. */
    public function __construct(
        public readonly string $providerPaymentId,
        public readonly array $clientAction = [],
        public readonly array $metadata = [],
    ) {}
}
