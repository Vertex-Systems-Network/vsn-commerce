<?php
namespace App\Domain\Shipping\Data;
use Carbon\CarbonImmutable;
/** Defines the ShipmentLabelResult class and its project responsibilities. */
final readonly class ShipmentLabelResult
{
    /** Initializes the ShipmentLabelResult instance and its dependencies. */
    public function __construct(
        public string $providerShipmentId,
        public string $trackingNumber,
        public ?string $labelUrl = null,
        public ?CarbonImmutable $estimatedDeliveryAt = null,
        public array $metadata = [],
    ) {}
}
