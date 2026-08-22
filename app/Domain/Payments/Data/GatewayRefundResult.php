<?php
namespace App\Domain\Payments\Data;
/** Defines the GatewayRefundResult class and its project responsibilities. */
class GatewayRefundResult
{
    /** Initializes the GatewayRefundResult instance and its dependencies. */
    public function __construct(
        public readonly string $providerTransactionId,
        public readonly string $status='succeeded',
        public readonly array $metadata=[],
    ) {}
}
