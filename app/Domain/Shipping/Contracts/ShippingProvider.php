<?php
namespace App\Domain\Shipping\Contracts;
use App\Domain\Shipping\Data\ShipmentLabelResult;
use App\Domain\Shipping\Data\VerifiedShippingWebhook;
use App\Models\Shipment;
/** Defines the ShippingProvider interface and its project responsibilities. */
interface ShippingProvider
{
    /** Handles code for the shipping provider workflow. */
    public function code(): string;
    /** Handles create shipment for the shipping provider workflow. */
    public function createShipment(Shipment $shipment, array $recipientSnapshot): ShipmentLabelResult;
    /** Handles verify webhook for the shipping provider workflow. */
    public function verifyWebhook(string $rawPayload, array $headers): VerifiedShippingWebhook;
    /** Handles lookup shipment for the shipping provider workflow. */
    public function lookupShipment(Shipment $shipment): array;
    /** Handles cancel shipment for the shipping provider workflow. */
    public function cancelShipment(Shipment $shipment): array;
}
