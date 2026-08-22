<?php
namespace App\Domain\Shipping\Data;
use App\Enums\ShipmentStatus;
use Carbon\CarbonImmutable;
/** Defines the VerifiedShippingWebhook class and its project responsibilities. */
final readonly class VerifiedShippingWebhook
{
    /** Initializes the VerifiedShippingWebhook instance and its dependencies. */
    public function __construct(
        public string $eventId,
        public ?string $providerShipmentId,
        public ?string $trackingNumber,
        public ShipmentStatus $status,
        public CarbonImmutable $occurredAt,
        public ?string $code = null,
        public ?string $message = null,
        public ?string $location = null,
        public array $payload = [],
    ) {}
}
