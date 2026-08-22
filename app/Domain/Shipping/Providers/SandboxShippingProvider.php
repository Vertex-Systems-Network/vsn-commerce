<?php
namespace App\Domain\Shipping\Providers;
use App\Domain\Shipping\Contracts\ShippingProvider;
use App\Domain\Shipping\Data\ShipmentLabelResult;
use App\Domain\Shipping\Data\VerifiedShippingWebhook;
use App\Domain\Shipping\Exceptions\ShippingException;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
/** Defines the SandboxShippingProvider class and its project responsibilities. */
final class SandboxShippingProvider implements ShippingProvider
{
    /** Handles code for the sandbox shipping provider workflow. */
    public function code(): string { return 'sandbox'; }
    /** Handles create shipment for the sandbox shipping provider workflow. */
    public function createShipment(Shipment $shipment, array $recipientSnapshot): ShipmentLabelResult
    {
        if (app()->environment('production')) throw new ShippingException('Sandbox shipping provider is disabled in production.');
        $suffix = strtoupper(substr(hash('sha256', $shipment->idempotency_key), 0, 12));
        return new ShipmentLabelResult(
            providerShipmentId: 'SBX-'.$suffix,
            trackingNumber: 'VSNS'.$suffix,
            labelUrl: null,
            estimatedDeliveryAt: $shipment->delivery_due_at ? CarbonImmutable::instance($shipment->delivery_due_at) : now()->toImmutable()->addDays(5),
            metadata: ['sandbox'=>true, 'recipient_country'=>$recipientSnapshot['country_code'] ?? null],
        );
    }
    /** Handles verify webhook for the sandbox shipping provider workflow. */
    public function verifyWebhook(string $rawPayload, array $headers): VerifiedShippingWebhook
    {
        if (app()->environment('production')) throw new ShippingException('Sandbox shipping provider is disabled in production.');
        $secret=(string)config('vsn.shipping.providers.sandbox.webhook_secret');
        if ($secret==='' || $secret==='change-me-in-local-env') throw new ShippingException('Sandbox shipping webhook secret is not configured.');
        $signature=$headers['x-vsn-signature'] ?? $headers['X-VSN-Signature'] ?? null;
        $expected='sha256='.hash_hmac('sha256',$rawPayload,$secret);
        if (!is_string($signature) || !hash_equals($expected,$signature)) throw new ShippingException('Invalid shipping webhook signature.');
        try { $data=json_decode($rawPayload,true,512,JSON_THROW_ON_ERROR); } catch (\JsonException $e) { throw new ShippingException('Malformed shipping webhook payload.', previous:$e); }
        $eventId=(string)($data['id'] ?? '');
        if ($eventId==='') throw new ShippingException('Shipping webhook event id is required.');
        $status=ShipmentStatus::tryFrom((string)($data['status'] ?? ''));
        if (!$status) throw new ShippingException('Unsupported shipping status.');
        $occurred=isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at']) : now()->toImmutable();
        return new VerifiedShippingWebhook(
            eventId:$eventId,
            providerShipmentId:isset($data['shipment_id'])?(string)$data['shipment_id']:null,
            trackingNumber:isset($data['tracking_number'])?(string)$data['tracking_number']:null,
            status:$status,
            occurredAt:$occurred,
            code:isset($data['code'])?(string)$data['code']:null,
            message:isset($data['message'])?(string)$data['message']:null,
            location:isset($data['location'])?(string)$data['location']:null,
            payload:$data,
        );
    }
    /** Handles lookup shipment for the sandbox shipping provider workflow. */
    public function lookupShipment(Shipment $shipment): array
    {
        return ['trackingNumber'=>$shipment->tracking_number,'status'=>$shipment->status->value,'raw'=>['sandbox'=>true]];
    }
    /** Handles cancel shipment for the sandbox shipping provider workflow. */
    public function cancelShipment(Shipment $shipment): array
    {
        if (app()->environment('production')) throw new ShippingException('Sandbox shipping provider is disabled in production.');
        return ['cancelled'=>true,'sandbox'=>true];
    }

}
